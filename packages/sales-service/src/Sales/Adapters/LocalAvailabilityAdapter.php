<?php

declare(strict_types=1);

namespace NM\Services\SalesService\Sales\Adapters;

use NM\Services\SalesService\Sales\Contracts\AvailabilityPort;
use NM\Services\SalesService\Sales\Contracts\CatalogItem;
use NM\Services\SalesService\Sales\Domain\DecimalQuantity;
use NM\Services\SalesService\Sales\SalesContext;
use Core\Database\Connection;
use RuntimeException;

final class LocalAvailabilityAdapter implements AvailabilityPort
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function assertAvailable(SalesContext $context, CatalogItem $item, string $quantity): void
    {
        unset($context);
        $requested = DecimalQuantity::toScaledInteger($quantity);
        $row = $this->connection->selectOne(
            'SELECT p.kind, i.id AS inventory_item_id, i.track_inventory
             FROM catalog_products p
             INNER JOIN catalog_variants v ON v.product_id = p.id AND v.is_default = 1
             LEFT JOIN inventory_items i ON i.variant_id = v.id AND i.status = ? AND i.archived_at IS NULL
             WHERE p.id = ? AND p.status = ? AND v.status = ?
             LIMIT 1',
            ['active', (int) $item->reference, 'active', 'active']
        );
        if ($row === null) {
            throw new RuntimeException('The catalog item is no longer available.');
        }
        if ($row['kind'] === 'service' || ($row['inventory_item_id'] !== null && !(bool) $row['track_inventory'])) {
            return;
        }
        if ($row['inventory_item_id'] === null) {
            throw new RuntimeException('Inventory tracking is not configured for this product.');
        }

        $stock = $this->connection->selectOne(
            'SELECT COALESCE(SUM(b.physical_qty - b.reserved_qty), 0) AS available_qty
             FROM inventory_stock_balances b
             INNER JOIN inventory_locations l ON l.id = b.location_id AND l.is_active = 1 AND l.is_available = 1
             INNER JOIN inventory_warehouses w ON w.id = l.warehouse_id AND w.is_active = 1
             INNER JOIN inventory_item_lots lot ON lot.id = b.lot_id AND lot.status = ?
             WHERE b.item_id = ?
               AND (lot.expires_at IS NULL OR lot.expires_at >= CURRENT_DATE)',
            ['available', (int) $row['inventory_item_id']]
        );
        $availableQuantity = (string) ($stock['available_qty'] ?? '0');
        $available = trim($availableQuantity, '0.') === ''
            ? 0
            : DecimalQuantity::toScaledInteger($availableQuantity);
        if ($available < $requested) {
            throw new RuntimeException('La quantité demandée n\'est pas disponible en stock. Veuillez ajuster la quantité et réessayer.');
        }
    }
}
