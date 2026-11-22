<?php

declare(strict_types=1);

namespace NM\Services\SalesService\Sales;

use NM\Services\SalesService\Sales\Contracts\AvailabilityPort;
use NM\Services\SalesService\Sales\Contracts\CatalogPort;
use NM\Services\SalesService\Sales\Domain\Cart;
use NM\Services\SalesService\Sales\Domain\CartLine;
use NM\Services\SalesService\Sales\Domain\DecimalQuantity;
use NM\Services\SalesService\Sales\Domain\Money;
use Core\Database\Connection;
use InvalidArgumentException;
use RuntimeException;

final class SalesCartService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SalesCartRepository $carts,
        private readonly SalesApplicationRegistry $applications,
        private readonly CatalogPort $catalog,
        private readonly AvailabilityPort $availability,
        private readonly int $maxLines = 100,
        private readonly int $maxQuantity = 1000,
        private readonly int $anonymousCartTtl = 86400,
    ) {
    }

    public function create(SalesContext $context, ?string $profile = null, ?string $currency = null): Cart
    {
        $context->requireScope('carts:write');
        $this->requireModule($context, 'cart');
        $this->applications->assertActiveContext($context);
        $application = $this->applications->forContext($context);

        $profile ??= (string) $application['profile'];
        $currency ??= (string) $application['currency'];
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $this->anonymousCartTtl);

        return $this->connection->transaction(
            fn (): Cart => $this->carts->create($context, $profile, $currency, $expiresAt)
        );
    }

    public function get(SalesContext $context, string $cartId): Cart
    {
        $context->requireScope('carts:read');
        $this->requireModule($context, 'cart');
        $this->applications->assertActiveContext($context);

        return $this->carts->find($context, $cartId)
            ?? throw new RuntimeException('Cart was not found.');
    }

    /**
     * @param array<string, scalar|null|array> $modifiers
     */
    public function addItem(
        SalesContext $context,
        string $cartId,
        string $itemReference,
        string $quantity,
        array $modifiers,
        int $expectedVersion,
        string $idempotencyKey,
    ): Cart {
        $context->requireScope('carts:write');
        $this->requireModule($context, 'cart');
        $this->requireModule($context, 'catalog');
        $this->requireModule($context, 'availability');
        $this->applications->assertActiveContext($context);
        if ($expectedVersion < 1 || $idempotencyKey === '' || strlen($idempotencyKey) > 190) {
            throw new InvalidArgumentException('Expected cart version or idempotency key is invalid.');
        }
        if (!preg_match('/^[1-9][0-9]{0,9}$/D', $cartId)) {
            throw new InvalidArgumentException('Cart identifier is invalid.');
        }
        if ($itemReference === '' || strlen($itemReference) > 190) {
            throw new InvalidArgumentException('Item reference is invalid.');
        }
        if (DecimalQuantity::toScaledInteger($quantity) > $this->maxQuantity * DecimalQuantity::SCALE) {
            throw new InvalidArgumentException('Cart item quantity limit exceeded.');
        }
        if (strlen(json_encode($modifiers, JSON_THROW_ON_ERROR)) > 8_192) {
            throw new InvalidArgumentException('Cart item modifiers exceed the maximum size.');
        }
        $operation = 'cart.add-item:' . $cartId;
        $requestHash = hash('sha256', json_encode([
            'item_reference' => $itemReference,
            'quantity' => $quantity,
            'modifiers' => $modifiers,
            'expected_version' => $expectedVersion,
        ], JSON_THROW_ON_ERROR));

        $recorded = $this->carts->idempotentResponse($context, $operation, $idempotencyKey);
        if ($recorded !== null) {
            if (!hash_equals((string) $recorded['request_hash'], $requestHash)) {
                throw new SalesCartConflictException('Idempotency key was reused with a different request.');
            }
            return $this->restoreRecordedCart($recorded);
        }

        $item = $this->catalog->findItem($context, $itemReference)
            ?? throw new RuntimeException('Catalog item was not found.');
        $this->availability->assertAvailable($context, $item, $quantity);
        $line = new CartLine(
            itemReference: $item->reference,
            label: $item->label,
            quantity: $quantity,
            unit: $item->unit,
            unitPrice: $item->unitPrice,
            modifiers: $modifiers,
            priceSource: $item->priceSource,
            pricedAt: gmdate('Y-m-d H:i:s'),
        );

        try {
            return $this->connection->transaction(function () use (
                $context,
                $cartId,
                $line,
                $expectedVersion,
                $idempotencyKey,
                $operation,
                $requestHash
            ): Cart {
                $recorded = $this->carts->idempotentResponse($context, $operation, $idempotencyKey);
                if ($recorded !== null) {
                    if (!hash_equals((string) $recorded['request_hash'], $requestHash)) {
                        throw new SalesCartConflictException('Idempotency key was reused with a different request.');
                    }

                    return $this->restoreRecordedCart($recorded);
                }

                $cart = $this->carts->find($context, $cartId)
                    ?? throw new RuntimeException('Cart was not found.');
                if ($cart->version !== $expectedVersion) {
                    throw new SalesCartConflictException('Cart version changed; reload the cart and retry.');
                }

                $updatedCart = $cart->add($line, $this->maxLines);
                $this->carts->addLine($context, $updatedCart, $line, $expectedVersion);
                $this->carts->appendOutboxEvent($context, 'CartItemAdded', $cartId, [
                    'cart_id' => $cartId,
                    'item_reference' => $line->itemReference,
                    'version' => $updatedCart->version,
                ]);
                $this->carts->recordIdempotency(
                    $context,
                    $operation,
                    $idempotencyKey,
                    $requestHash,
                    $this->serializeCart($updatedCart),
                );

                return $updatedCart;
            });
        } catch (SalesCartConflictException $exception) {
            $recorded = $this->carts->idempotentResponse($context, $operation, $idempotencyKey);
            if ($recorded !== null) {
                if (!hash_equals((string) $recorded['request_hash'], $requestHash)) {
                    throw $exception;
                }

                return $this->restoreRecordedCart($recorded);
            }

            throw $exception;
        }
    }

    private function requireModule(SalesContext $context, string $module): void
    {
        if (!$this->applications->isModuleEnabled($context, $module)) {
            throw new RuntimeException("Sales module [{$module}] is not enabled for this application.");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCart(Cart $cart): array
    {
        return [
            'id' => $cart->id,
            'application_id' => $cart->applicationId,
            'tenant_id' => $cart->tenantId,
            'profile' => $cart->profile,
            'currency' => $cart->currency,
            'version' => $cart->version,
            'lines' => array_map(static fn (CartLine $line): array => [
                'item_reference' => $line->itemReference,
                'label' => $line->label,
                'quantity' => $line->quantity,
                'unit' => $line->unit,
                'unit_amount_minor' => $line->unitPrice->minorAmount,
                'currency' => $line->unitPrice->currency,
                'modifiers' => $line->modifiers,
                'price_source' => $line->priceSource,
                'priced_at' => $line->pricedAt,
            ], $cart->lines),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function restoreCart(array $data): Cart
    {
        if (!isset($data['id'], $data['application_id'], $data['profile'], $data['currency'], $data['version'])
            || !is_array($data['lines'] ?? null)) {
            throw new RuntimeException('Stored idempotent cart response is invalid.');
        }

        $lines = [];
        foreach ($data['lines'] as $line) {
            if (!is_array($line)) {
                throw new RuntimeException('Stored idempotent cart line is invalid.');
            }
            $lines[] = new CartLine(
                (string) $line['item_reference'],
                (string) $line['label'],
                (string) $line['quantity'],
                (string) $line['unit'],
                new Money((int) $line['unit_amount_minor'], (string) $line['currency']),
                (array) $line['modifiers'],
                (string) $line['price_source'],
                isset($line['priced_at']) ? (string) $line['priced_at'] : null,
            );
        }

        return new Cart(
            (string) $data['id'],
            (int) $data['application_id'],
            isset($data['tenant_id']) ? (int) $data['tenant_id'] : null,
            (string) $data['profile'],
            (string) $data['currency'],
            (int) $data['version'],
            $lines,
        );
    }

    /**
     * @param array<string, mixed> $recorded
     */
    private function restoreRecordedCart(array $recorded): Cart
    {
        if (!is_string($recorded['response_json'] ?? null)) {
            throw new RuntimeException('Idempotent cart response is incomplete.');
        }

        $data = json_decode($recorded['response_json'], true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Stored idempotent cart response is invalid.');
        }

        return $this->restoreCart($data);
    }
}
