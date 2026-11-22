<?php

declare(strict_types=1);

namespace NM\Services\SalesService\Sales;

use NM\Services\SalesService\Sales\Domain\Cart;
use NM\Services\SalesService\Sales\Domain\CartLine;
use NM\Services\SalesService\Sales\Domain\Money;
use Core\Database\Connection;
use RuntimeException;

final class SalesCartRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function create(SalesContext $context, string $profile, string $currency, ?string $expiresAt): Cart
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->connection->insert(
            'INSERT INTO sales_carts (application_id, tenant_id, profile, status, currency, service_mode, service_details_json, version, expires_at, order_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NULL, ?, 1, ?, NULL, ?, ?)',
            [
                $context->applicationId,
                $context->tenantId,
                $profile,
                'open',
                $currency,
                '{}',
                $expiresAt,
                $now,
                $now,
            ]
        );

        return new Cart(
            (string) $this->connection->lastInsertId(),
            $context->applicationId,
            $context->tenantId,
            $profile,
            $currency
        );
    }

    public function find(SalesContext $context, string $cartId): ?Cart
    {
        $scopeSql = $context->tenantId === null ? 'tenant_id IS NULL' : 'tenant_id = ?';
        $bindings = [$cartId, $context->applicationId];
        if ($context->tenantId !== null) {
            $bindings[] = $context->tenantId;
        }

        $row = $this->connection->selectOne(
            "SELECT id, application_id, tenant_id, profile, currency, version FROM sales_carts WHERE id = ? AND application_id = ? AND {$scopeSql} AND status = ? AND (expires_at IS NULL OR expires_at > ?)",
            [...$bindings, 'open', gmdate('Y-m-d H:i:s')]
        );
        if ($row === null) {
            return null;
        }

        $lineBindings = [(int) $row['id'], $context->applicationId];
        if ($context->tenantId !== null) {
            $lineBindings[] = $context->tenantId;
        }
        $lineTenantScope = $context->tenantId === null ? 'tenant_id IS NULL' : 'tenant_id = ?';
        $items = $this->connection->select(
            "SELECT item_reference, label_snapshot, quantity, unit, unit_amount_minor, currency, modifiers_json, price_source, priced_at FROM sales_cart_items WHERE cart_id = ? AND application_id = ? AND {$lineTenantScope} ORDER BY id ASC",
            $lineBindings
        );

        $lines = [];
        foreach ($items as $item) {
            $modifiers = json_decode((string) $item['modifiers_json'], true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($modifiers)) {
                throw new RuntimeException('Stored cart modifiers are invalid.');
            }
            $lines[] = new CartLine(
                itemReference: (string) $item['item_reference'],
                label: (string) $item['label_snapshot'],
                quantity: (string) $item['quantity'],
                unit: (string) $item['unit'],
                unitPrice: new Money((int) $item['unit_amount_minor'], (string) $item['currency']),
                modifiers: $modifiers,
                priceSource: (string) $item['price_source'],
                pricedAt: (string) $item['priced_at'],
            );
        }

        return new Cart(
            (string) $row['id'],
            (int) $row['application_id'],
            $row['tenant_id'] === null ? null : (int) $row['tenant_id'],
            (string) $row['profile'],
            (string) $row['currency'],
            (int) $row['version'],
            $lines,
        );
    }

    public function addLine(SalesContext $context, Cart $cart, CartLine $line, int $expectedVersion): void
    {
        $scopeSql = $context->tenantId === null ? 'tenant_id IS NULL' : 'tenant_id = ?';
        $bindings = [$cart->version, gmdate('Y-m-d H:i:s'), $cart->id, $context->applicationId];
        if ($context->tenantId !== null) {
            $bindings[] = $context->tenantId;
        }
        $bindings[] = $expectedVersion;
        $changed = $this->connection->update(
            "UPDATE sales_carts SET version = ?, updated_at = ? WHERE id = ? AND application_id = ? AND {$scopeSql} AND version = ? AND status = ?",
            [...$bindings, 'open']
        );
        if ($changed !== 1) {
            throw new SalesCartConflictException('Cart version changed; reload the cart and retry.');
        }

        $this->connection->insert(
            'INSERT INTO sales_cart_items (application_id, tenant_id, cart_id, item_reference, label_snapshot, quantity, unit, unit_amount_minor, currency, modifiers_json, price_source, priced_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $context->applicationId,
                $context->tenantId,
                (int) $cart->id,
                $line->itemReference,
                $line->label,
                $line->quantity,
                $line->unit,
                $line->unitPrice->minorAmount,
                $line->unitPrice->currency,
                json_encode($line->modifiers, JSON_THROW_ON_ERROR),
                $line->priceSource,
                $line->pricedAt ?? gmdate('Y-m-d H:i:s'),
                gmdate('Y-m-d H:i:s'),
                gmdate('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function idempotentResponse(SalesContext $context, string $operation, string $key): ?array
    {
        $row = $this->connection->selectOne(
            'SELECT request_hash, response_json FROM sales_idempotency WHERE application_id = ? AND tenant_id ' . ($context->tenantId === null ? 'IS NULL' : '= ?') . ' AND operation = ? AND idempotency_key = ?',
            $context->tenantId === null
                ? [$context->applicationId, $operation, $key]
                : [$context->applicationId, $context->tenantId, $operation, $key]
        );

        return $row;
    }

    /**
     * @param array<string, mixed> $response
     */
    public function recordIdempotency(
        SalesContext $context,
        string $operation,
        string $key,
        string $requestHash,
        array $response,
    ): void {
        $now = gmdate('Y-m-d H:i:s');
        $this->connection->insert(
            'INSERT INTO sales_idempotency (application_id, tenant_id, operation, idempotency_key, request_hash, response_json, status, expires_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, ?)',
            [
                $context->applicationId,
                $context->tenantId,
                $operation,
                $key,
                $requestHash,
                json_encode($response, JSON_THROW_ON_ERROR),
                'completed',
                $now,
                $now,
            ]
        );
    }

    public function appendOutboxEvent(
        SalesContext $context,
        string $eventType,
        string $aggregateId,
        array $payload,
        string $aggregateType = 'cart',
    ): void {
        $now = gmdate('Y-m-d H:i:s');
        $eventId = self::uuid();
        $this->connection->insert(
            'INSERT INTO sales_outbox (application_id, tenant_id, event_id, event_type, aggregate_type, aggregate_id, payload_json, status, attempts, available_at, delivered_at, last_error_code, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, NULL, NULL, ?, ?)',
            [
                $context->applicationId,
                $context->tenantId,
                $eventId,
                $eventType,
                $aggregateType,
                $aggregateId,
                json_encode($payload, JSON_THROW_ON_ERROR),
                'pending',
                $now,
                $now,
                $now,
            ]
        );
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
