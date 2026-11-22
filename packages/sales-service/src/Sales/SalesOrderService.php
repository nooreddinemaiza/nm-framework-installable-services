<?php

declare(strict_types=1);

namespace NM\Services\SalesService\Sales;

use NM\Services\SalesService\Sales\Domain\Money;
use NM\Services\SalesService\Sales\Domain\SalesOrder;
use NM\Services\SalesService\Sales\Domain\SalesOrderState;
use Core\Database\Connection;
use InvalidArgumentException;
use RuntimeException;

final class SalesOrderService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SalesApplicationRegistry $applications,
        private readonly SalesOrderRepository $orders,
        private readonly SalesInventoryFinalizer $inventoryFinalizer,
        private readonly SalesOrderFinancialService $financials,
    ) {
    }

    public function get(SalesContext $context, string $orderId): SalesOrder
    {
        $context->requireScope('orders:read');
        $this->requireOrdersModule($context);
        $this->validateOrderId($orderId);

        return $this->orders->find($context, $orderId)
            ?? throw new RuntimeException('Sales order was not found.');
    }

    public function transition(
        SalesContext $context,
        string $orderId,
        int $expectedVersion,
        string $newStatus,
        string $reason = '',
    ): SalesOrder {
        $context->requireScope('orders:manage');
        $this->requireOrdersModule($context);
        $this->validateOrderId($orderId);
        if ($expectedVersion < 1 || strlen($reason) > 190) {
            throw new InvalidArgumentException('Sales order transition input is invalid.');
        }

        return $this->connection->transaction(function () use (
            $context,
            $orderId,
            $expectedVersion,
            $newStatus,
            $reason
        ): SalesOrder {
            $order = $this->orders->find($context, $orderId)
                ?? throw new RuntimeException('Sales order was not found.');
            if ($order->version !== $expectedVersion) {
                throw new SalesCartConflictException('Sales order version changed; reload it and retry.');
            }
            if (!SalesOrderState::allows($order->status, $newStatus)) {
                throw new InvalidArgumentException(
                    "Sales order transition [{$order->status} -> {$newStatus}] is not allowed."
                );
            }
            $financial = $this->financials->paymentSummaryForOrder($context, $order);
            if ($newStatus === SalesOrderState::COMPLETED && $financial['balance_due_minor'] > 0) {
                throw new InvalidArgumentException('Une commande doit être intégralement payée avant sa clôture.');
            }
            if ($newStatus === SalesOrderState::CANCELLED
                && $financial['paid_minor'] > $financial['refunded_minor']) {
                throw new InvalidArgumentException('Remboursez les paiements reçus avant d’annuler la commande.');
            }

            if ($newStatus === SalesOrderState::COMPLETED) {
                $this->inventoryFinalizer->finalize($order);
            }

            return $this->orders->transition($context, $order, $newStatus, trim($reason));
        });
    }

    /**
     * @param array{name: string, phone: string, email: string} $customer
     * @param list<array{item_reference: string, label: string, quantity: string, unit: string, unit_amount_minor: int, line_amount_minor: int}> $items
     * @return array{id: string, public_reference: string}
     */
    public function createDashboardOrder(
        SalesContext $context,
        string $currency,
        array $customer,
        array $items,
        string $note,
    ): array {
        $context->requireScope('orders:manage');
        $this->requireDashboardScope($context);
        $this->requireOrdersModule($context);
        $this->validateManualOrder($currency, $customer, $items, $note);

        return $this->connection->transaction(fn(): array => $this->orders->createManualDashboardOrder(
            $context->applicationId,
            $currency,
            $customer,
            $items,
            $note,
            $context->actorReference,
        ));
    }

    /**
     * @param array{name: string, phone: string, email: string} $customer
     * @param list<array{item_reference: string, label: string, quantity: string, unit: string, unit_amount_minor: int, line_amount_minor: int}>|null $items
     */
    public function updateDashboardOrder(
        SalesContext $context,
        string $orderId,
        int $expectedVersion,
        array $customer,
        string $note,
        ?array $items,
    ): void {
        $context->requireScope('orders:manage');
        $this->requireDashboardScope($context);
        $this->requireOrdersModule($context);
        $this->validateOrderId($orderId);
        if ($expectedVersion < 1) {
            throw new InvalidArgumentException('Sales order update input is invalid.');
        }
        if ($items !== null) {
            $existing = $this->orders->find($context, $orderId)
                ?? throw new RuntimeException('Sales order was not found.');
            $this->validateManualOrder($existing->total->currency, $customer, $items, $note);
        } elseif (strlen($note) > 1000 || strlen($customer['name']) > 190
            || strlen($customer['phone']) > 80 || strlen($customer['email']) > 190
            || ($customer['email'] !== '' && filter_var($customer['email'], FILTER_VALIDATE_EMAIL) === false)) {
            throw new InvalidArgumentException('Customer details are invalid.');
        }

        $this->connection->transaction(function () use (
            $context,
            $orderId,
            $expectedVersion,
            $customer,
            $note,
            $items
        ): void {
            $current = $this->orders->find($context, $orderId)
                ?? throw new RuntimeException('Sales order was not found.');
            if ($items !== null && $this->financials->hasPaymentHistory($context, $current)) {
                throw new InvalidArgumentException('Les lignes d’une commande avec historique financier ne peuvent pas être modifiées.');
            }
            $this->orders->updateDashboardOrder(
                $context->applicationId,
                $orderId,
                $expectedVersion,
                $customer,
                $note,
                $items,
            );
        });
    }

    public function deleteDashboardOrder(SalesContext $context, string $orderId): void
    {
        $context->requireScope('orders:manage');
        $this->requireDashboardScope($context);
        $this->validateOrderId($orderId);
        $this->connection->transaction(function () use ($context, $orderId): void {
            $order = $this->orders->find($context, $orderId)
                ?? throw new RuntimeException('Sales order was not found.');
            if ($this->financials->hasPaymentHistory($context, $order)) {
                throw new InvalidArgumentException('Une commande avec historique financier ne peut pas être supprimée.');
            }
            $this->orders->deleteDashboardOrder($context->applicationId, $orderId);
        });
    }

    /**
     * @param array{name: string, phone: string, email: string} $customer
     * @param list<array{item_reference: string, label: string, quantity: string, unit: string, unit_amount_minor: int, line_amount_minor: int}> $items
     */
    private function validateManualOrder(string $currency, array $customer, array $items, string $note): void
    {
        if (!preg_match('/^[A-Z]{3}$/D', $currency) || $items === [] || count($items) > 50
            || strlen($note) > 1000 || strlen($customer['name']) > 190
            || strlen($customer['phone']) > 80 || strlen($customer['email']) > 190
            || ($customer['email'] !== '' && filter_var($customer['email'], FILTER_VALIDATE_EMAIL) === false)) {
            throw new InvalidArgumentException('Les informations de commande sont invalides.');
        }
        $total = new Money(0, $currency);
        foreach ($items as $item) {
            if (!preg_match('/^[1-9][0-9]{0,9}$/D', $item['item_reference'])
                || $item['label'] === '' || strlen($item['label']) > 190 || strlen($item['unit']) > 30
                || !preg_match('/^(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,6})?$/D', $item['quantity'])
                || (float) $item['quantity'] <= 0
                || $item['unit_amount_minor'] < 0 || $item['unit_amount_minor'] > 99_999_999_999) {
                throw new InvalidArgumentException('Une ou plusieurs lignes de commande sont invalides.');
            }
            $calculated = (new Money($item['unit_amount_minor'], $currency))->multiplyQuantity($item['quantity']);
            if ($calculated->minorAmount !== $item['line_amount_minor']) {
                throw new InvalidArgumentException('Le total d’une ligne de commande est invalide.');
            }
            $total = $total->add($calculated);
        }
    }

    private function validateOrderId(string $orderId): void
    {
        if (!preg_match('/^[1-9][0-9]{0,9}$/D', $orderId)) {
            throw new InvalidArgumentException('Sales order identifier is invalid.');
        }
    }

    private function requireDashboardScope(SalesContext $context): void
    {
        if ($context->tenantId !== null) {
            throw new SalesAuthorizationException('Dashboard order operations require a non-tenant context.');
        }
    }

    private function requireOrdersModule(SalesContext $context): void
    {
        if (!$this->applications->isModuleEnabled($context, 'orders')) {
            throw new RuntimeException('Sales orders module is not enabled for this application.');
        }
    }
}
