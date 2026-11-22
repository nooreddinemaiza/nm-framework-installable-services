<?php

declare(strict_types=1);

namespace NM\Services\SalesService\Sales;

use NM\Services\InventoryService\Inventory\InventoryFacade;
use NM\Services\InventoryService\Inventory\InventoryMovement;
use NM\Services\InventoryService\Inventory\InventoryQuantity;
use NM\Services\SalesService\Sales\Domain\SalesOrder;
use Core\Database\Connection;

final class SalesInventoryFinalizer
{
    public function __construct(
        private readonly Connection $connection,
        private readonly InventoryFacade $inventory,
    ) {
    }

    public function finalize(SalesOrder $order): void
    {
        $dashboardManual = ($order->details['dashboard_manual'] ?? false) === true;

        foreach ($order->lines as $lineIndex => $line) {
            $reference = (string) ($line['item_reference'] ?? '');
            if (!preg_match('/^[1-9][0-9]{0,9}$/D', $reference)) {
                if ($dashboardManual) {
                    throw new SalesInventoryFinalizationException(
                        'Reliez chaque ligne de cette commande manuelle à un produit du catalogue avant de la terminer.'
                    );
                }
                continue;
            }

            $item = $this->connection->selectOne(
                'SELECT i.id, i.track_inventory, i.tracking_mode, i.reference_unit_id,
                        i.picking_strategy, i.status AS inventory_status, i.archived_at,
                        u.code AS unit_code, p.kind
                 FROM catalog_products p
                 INNER JOIN catalog_variants v ON v.product_id = p.id AND v.is_default = 1
                 LEFT JOIN inventory_items i ON i.variant_id = v.id
                 LEFT JOIN inventory_units u ON u.id = i.reference_unit_id
                 WHERE p.id = ? AND p.status = ? AND v.status = ?
                 LIMIT 1',
                [(int) $reference, 'active', 'active']
            );
            if ($item === null) {
                if ($dashboardManual) {
                    throw new SalesInventoryFinalizationException(sprintf(
                        'Le produit [%s] de la commande manuelle n’est plus disponible dans le catalogue.',
                        $reference
                    ));
                }
                continue;
            }
            if ($item['id'] === null || !(bool) $item['track_inventory'] || (string) $item['kind'] !== 'goods') {
                continue;
            }
            if ((string) $item['inventory_status'] !== 'active' || $item['archived_at'] !== null) {
                throw new SalesInventoryFinalizationException(sprintf(
                    'Le suivi de stock du produit [%s] est inactif; réactivez-le avant de terminer la commande.',
                    $line['label'] ?? $reference
                ));
            }

            if ((string) $item['tracking_mode'] === 'serial') {
                throw new SalesInventoryFinalizationException(sprintf(
                    'La finalisation automatique des commandes ne prend pas encore en charge les articles suivis par numéro de série (produit [%s]).',
                    $line['label'] ?? $reference
                ));
            }

            $lineUnit = strtoupper((string) ($line['unit'] ?? ''));
            if ($lineUnit !== strtoupper((string) $item['unit_code'])) {
                throw new SalesInventoryFinalizationException(sprintf(
                    'L’unité de la commande [%s] ne correspond plus à l’unité de stock [%s] pour le produit [%s].',
                    $lineUnit,
                    $item['unit_code'],
                    $line['label'] ?? $reference
                ));
            }

            $quantity = (string) ($line['quantity'] ?? '');
            $lockClause = $this->connection->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite'
                ? ''
                : ' FOR UPDATE';
            $balances = $this->connection->select(
                'SELECT b.id, b.location_id, b.lot_id, b.physical_qty, b.reserved_qty,
                        l.expires_at, l.manufactured_at, l.created_at,
                        CASE WHEN w.default_location_id = b.location_id THEN 0 ELSE 1 END AS location_priority
                 FROM inventory_stock_balances b
                 INNER JOIN inventory_locations loc ON loc.id = b.location_id
                 INNER JOIN inventory_warehouses w ON w.id = loc.warehouse_id
                 INNER JOIN inventory_item_lots l ON l.id = b.lot_id
                 WHERE b.item_id = ?
                   AND loc.is_active = 1 AND loc.is_available = 1
                   AND w.is_active = 1
                   AND l.status = ?
                   AND (l.expires_at IS NULL OR l.expires_at >= CURRENT_DATE)
                 ORDER BY location_priority ASC, '
                    . self::lotOrderBy((string) ($item['picking_strategy'] ?? ''))
                    . $lockClause,
                [(int) $item['id'], 'available']
            );
            $allocations = self::planAllocations($balances, $quantity, (string) ($line['label'] ?? $reference));

            foreach ($allocations as $allocationIndex => $allocation) {
                $this->inventory->applyMovement(new InventoryMovement(
                    movementType: 'issue',
                    itemId: (int) $item['id'],
                    locationId: $allocation['location_id'],
                    lotId: $allocation['lot_id'],
                    quantity: $allocation['quantity'],
                    context: [
                        'unit_id' => (int) $item['reference_unit_id'],
                        'reference' => $order->publicReference ?? 'sales-order:' . $order->id,
                        'reason' => 'Vente finalisée depuis Sales.',
                        'idempotency_key' => sprintf(
                            'sales-order:%s:line:%d:allocation:%d',
                            $order->id,
                            $lineIndex,
                            $allocationIndex
                        ),
                        'external_module' => 'sales',
                        'external_document_type' => 'order',
                        'external_document_id' => $order->id,
                    ],
                ));
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $balances
     * @return list<array{location_id: int, lot_id: int, quantity: string}>
     */
    public static function planAllocations(array $balances, string $quantity, string $label): array
    {
        $remaining = new InventoryQuantity($quantity);
        if (!$remaining->isPositive()) {
            throw new SalesInventoryFinalizationException(sprintf('La quantité vendue pour [%s] est invalide.', $label));
        }

        $availableTotal = InventoryQuantity::zero();
        $allocations = [];
        foreach ($balances as $balance) {
            $available = (new InventoryQuantity((string) $balance['physical_qty']))
                ->subtract(new InventoryQuantity((string) $balance['reserved_qty']));
            if (!$available->isPositive()) {
                continue;
            }
            $availableTotal = $availableTotal->add($available);
            $allocated = InventoryQuantity::compare($available->toString(), $remaining->toString()) < 0
                ? $available
                : $remaining;
            $allocations[] = [
                'location_id' => (int) $balance['location_id'],
                'lot_id' => (int) $balance['lot_id'],
                'quantity' => $allocated->toString(),
            ];
            $remaining = $remaining->subtract($allocated);
            if ($remaining->isZero()) {
                return $allocations;
            }
        }

        throw new SalesInventoryFinalizationException(sprintf(
            'Stock disponible insuffisant pour [%s] : demandé [%s], disponible [%s].',
            $label,
            $quantity,
            $availableTotal->toString()
        ));
    }

    private static function lotOrderBy(string $strategy): string
    {
        return strtolower($strategy) === 'fifo'
            ? 'l.created_at ASC, l.id ASC'
            : 'CASE WHEN l.expires_at IS NULL THEN 1 ELSE 0 END ASC, l.expires_at ASC, l.created_at ASC, l.id ASC';
    }
}
