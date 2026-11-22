<?php

declare(strict_types=1);

namespace NM\Services\SalesService\Sales;

use NM\Services\SalesService\Sales\Contracts\AvailabilityPort;
use NM\Services\SalesService\Sales\Contracts\CatalogPort;
use NM\Services\SalesService\Sales\Domain\Cart;
use NM\Services\SalesService\Sales\Domain\PriceQuote;
use NM\Services\SalesService\Sales\Domain\SalesOrder;
use Core\Database\Connection;
use InvalidArgumentException;
use RuntimeException;

final class SalesCheckoutService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SalesCartRepository $carts,
        private readonly SalesApplicationRegistry $applications,
        private readonly CatalogPort $catalog,
        private readonly AvailabilityPort $availability,
        private readonly SalesPricingService $pricing,
        private readonly SalesOrderRepository $orders,
    ) {
    }

    public function checkout(
        SalesContext $context,
        string $cartId,
        int $expectedVersion,
        string $idempotencyKey,
        array $orderDetails = [],
    ): SalesOrder {
        $context->requireScope('checkout:write');
        if (!$this->applications->isModuleEnabled($context, 'checkout')) {
            throw new RuntimeException('Sales checkout module is not enabled for this application.');
        }
        if (!preg_match('/^[1-9][0-9]{0,9}$/D', $cartId)
            || $expectedVersion < 1
            || $idempotencyKey === ''
            || strlen($idempotencyKey) > 190) {
            throw new InvalidArgumentException('Checkout cart, version or idempotency key is invalid.');
        }

        $operation = 'cart.checkout:' . $cartId;
        $requestHash = hash('sha256', json_encode([
            'cart_id' => $cartId,
            'expected_version' => $expectedVersion,
            'order_details' => $orderDetails,
        ], JSON_THROW_ON_ERROR));
        $recorded = $this->recordedOrder($context, $operation, $idempotencyKey, $requestHash);
        if ($recorded !== null) {
            return $recorded;
        }

        $cart = $this->carts->find($context, $cartId)
            ?? throw new RuntimeException('Cart was not found or has expired.');
        if ($cart->version !== $expectedVersion) {
            throw new SalesCartConflictException('Cart version changed; reload the cart and retry.');
        }
        if ($cart->lines === []) {
            throw new InvalidArgumentException('An empty cart cannot be checked out.');
        }

        [$quote, $discountSnapshot] = $this->revalidateAndPrice($context, $cart);
        $orderDetails['discounts'] = $discountSnapshot;

        try {
            return $this->connection->transaction(function () use (
                $context,
                $cartId,
                $expectedVersion,
                $idempotencyKey,
                $operation,
                $requestHash,
                $quote,
                $orderDetails
            ): SalesOrder {
                $recorded = $this->recordedOrder($context, $operation, $idempotencyKey, $requestHash);
                if ($recorded !== null) {
                    return $recorded;
                }

                $cart = $this->carts->find($context, $cartId)
                    ?? throw new SalesCartConflictException('Cart changed or expired during checkout.');
                if ($cart->version !== $expectedVersion) {
                    throw new SalesCartConflictException('Cart version changed; reload the cart and retry.');
                }

                return $this->orders->createFromCart(
                    $context,
                    $cart,
                    $quote,
                    $idempotencyKey,
                    $requestHash,
                    $orderDetails,
                );
            });
        } catch (SalesCartConflictException $exception) {
            $recorded = $this->recordedOrder($context, $operation, $idempotencyKey, $requestHash);
            if ($recorded !== null) {
                return $recorded;
            }

            throw $exception;
        }
    }

    /**
     * @return array{PriceQuote, list<array<string, mixed>>}
     */
    private function revalidateAndPrice(SalesContext $context, Cart $cart): array
    {
        $discountSnapshot = [];
        foreach ($cart->lines as $index => $line) {
            $current = $this->catalog->findItem($context, $line->itemReference)
                ?? throw new RuntimeException("Catalog item [{$line->itemReference}] is no longer available.");
            if ($current->reference !== $line->itemReference
                || $current->unitPrice->minorAmount !== $line->unitPrice->minorAmount
                || $current->unitPrice->currency !== $line->unitPrice->currency
                || $current->priceSource !== $line->priceSource) {
                throw new SalesCartConflictException(
                    "Price changed for catalog item [{$line->itemReference}]; refresh the cart before checkout."
                );
            }
            $this->availability->assertAvailable($context, $current, $line->quantity);
            $originalUnitPrice = $current->originalUnitPrice ?? $current->unitPrice;
            $originalLineTotal = $originalUnitPrice->multiplyQuantity($line->quantity);
            $discountSnapshot[] = [
                'item_reference' => $line->itemReference,
                'original_unit_amount_minor' => $originalUnitPrice->minorAmount,
                'original_line_amount_minor' => $originalLineTotal->minorAmount,
                'saving_minor' => $originalLineTotal->minorAmount - $line->total()->minorAmount,
                'applied_discounts' => $current->discount?->appliedDiscounts ?? [],
            ];
        }

        return [$this->pricing->quote(
            $cart,
            $this->applications->moduleConfiguration($context, 'pricing'),
        ), $discountSnapshot];
    }

    private function recordedOrder(
        SalesContext $context,
        string $operation,
        string $idempotencyKey,
        string $requestHash,
    ): ?SalesOrder {
        $recorded = $this->carts->idempotentResponse($context, $operation, $idempotencyKey);
        if ($recorded === null) {
            return null;
        }
        if (!hash_equals((string) $recorded['request_hash'], $requestHash)) {
            throw new SalesCartConflictException('Idempotency key was reused with a different request.');
        }
        if (!is_string($recorded['response_json'])) {
            throw new RuntimeException('Idempotent checkout response is incomplete.');
        }

        $response = json_decode($recorded['response_json'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($response) || !is_string($response['order_id'] ?? null)) {
            throw new RuntimeException('Stored idempotent checkout response is invalid.');
        }

        return $this->orders->find($context, $response['order_id'])
            ?? throw new RuntimeException('Idempotent checkout order could not be loaded.');
    }
}
