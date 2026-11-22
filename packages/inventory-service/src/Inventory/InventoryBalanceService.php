<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Inventory;

use Core\Database\Connection;
use NM\Services\InventoryService\Models\CatalogProduct;
use NM\Services\InventoryService\Models\CatalogVariant;
use NM\Services\InventoryService\Models\InventoryRecord;
use Core\Exceptions\Http\NotFoundHttpException;
use Core\Exceptions\Http\UnprocessableEntityException;
use Core\Support\Data\Data;
use DateTimeImmutable;

final class InventoryBalanceService
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function applyDelta(int $itemId, int $locationId, int $lotId, string $delta, ?int $balanceId = null): InventoryBalance
    {
        $this->assertTransactionOpen();
        $this->validateIdentifiers($itemId, $locationId, $lotId);
        $this->validateQuantity($delta);

        $deltaQuantity = new InventoryQuantity($delta);
        $row = $this->fetchBalanceForUpdate($itemId, $locationId, $lotId);

        if ($row === null) {
            $now = date('Y-m-d H:i:s');
            InventoryRecord::table('inventory_stock_balances')->insert([
                'item_id' => $itemId,
                'location_id' => $locationId,
                'lot_id' => $lotId,
                'physical_qty' => '0',
                'reserved_qty' => '0',
                'updated_at' => $now,
            ]);
            $row = $this->fetchBalanceForUpdate($itemId, $locationId, $lotId);
        }

        $currentQty = new InventoryQuantity((string) ($row['physical_qty'] ?? '0'));
        $nextQty = $currentQty->add($deltaQuantity);

        if ($nextQty->isNegative()) {
            throw new UnprocessableEntityException([
                'quantity' => [sprintf('Le stock ne peut pas être négatif pour l’article [%d], l’emplacement [%d] et le lot [%d].', $itemId, $locationId, $lotId)],
            ]);
        }

        InventoryRecord::table('inventory_stock_balances')->where('id', '=', $row['id'])->update([
            'physical_qty' => $nextQty->toString(),
            'updated_at' => date('Y-m-d H:i:s'),
            'last_entry_id' => $balanceId ?? $row['last_entry_id'] ?? null,
        ]);

        return new InventoryBalance(
            id: (int) $row['id'],
            itemId: $itemId,
            locationId: $locationId,
            lotId: $lotId,
            physicalQty: $nextQty->toString(),
            reservedQty: (string) ($row['reserved_qty'] ?? '0'),
            raw: $row,
        );
    }

    public function reserve(int $itemId, int $locationId, int $lotId, string $quantity): int
    {
        $this->assertTransactionOpen();
        $this->validateIdentifiers($itemId, $locationId, $lotId);
        $this->validateQuantity($quantity, positive: true);

        $balance = $this->getBalance($itemId, $locationId, $lotId);
        if (!$balance->availableForReservation($quantity)) {
            throw new UnprocessableEntityException([
                'quantity' => [sprintf('Stock disponible insuffisant pour réserver [%s] pour l’article [%d] à l’emplacement [%d].', $quantity, $itemId, $locationId)],
            ]);
        }

        $now = date('Y-m-d H:i:s');
        $reservationId = InventoryRecord::table('inventory_stock_reservations')->insertGetId([
            'item_id' => $itemId,
            'warehouse_id' => $locationId,
            'quantity_reserved' => $quantity,
            'quantity_consumed' => '0',
            'quantity_released' => '0',
            'status' => 'active',
            'reservation_key' => 'inventory:' . uniqid('', true),
            'expires_at' => null,
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $newReserved = (new InventoryQuantity($balance->reservedQty))
            ->add(new InventoryQuantity($quantity));
        InventoryRecord::table('inventory_stock_balances')->where('id', '=', $balance->id)->update([
            'reserved_qty' => $newReserved->toString(),
            'updated_at' => $now,
        ]);

        return $reservationId;
    }

    public function getBalance(int $itemId, int $locationId, int $lotId): InventoryBalance
    {
        $this->validateIdentifiers($itemId, $locationId, $lotId);
        $row = $this->fetchBalanceForUpdate($itemId, $locationId, $lotId);
        if ($row === null) {
            throw new NotFoundHttpException(sprintf('Aucun solde de stock pour l’article [%d], l’emplacement [%d] et le lot [%d].', $itemId, $locationId, $lotId));
        }

        return new InventoryBalance(
            id: (int) $row['id'],
            itemId: $itemId,
            locationId: $locationId,
            lotId: $lotId,
            physicalQty: (string) ($row['physical_qty'] ?? '0'),
            reservedQty: (string) ($row['reserved_qty'] ?? '0'),
            raw: $row,
        );
    }

    /**
     * @return array{
     *     last_movement: array<string, mixed>|null,
     *     last_receipt: array<string, mixed>|null,
     *     out_of_stock: array<string, mixed>|null
     * }
     */
    public function dashboardSnapshot(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now');
        $since = $now->modify('-1 hour')->format('Y-m-d H:i:s');

        return [
            'last_movement' => $this->latestMovement(since: $since),
            'last_receipt' => $this->latestMovement(type: 'receipt'),
            'out_of_stock' => $this->firstOutOfStockItem(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestMovement(?string $type = null, ?string $since = null): ?array
    {
        $query = InventoryRecord::table('inventory_stock_ledger_entries as e')
            ->join('inventory_items as i', 'i.id', '=', 'e.item_id')
            ->join(CatalogVariant::getTable() . ' as v', 'v.id', '=', 'i.variant_id')
            ->join(CatalogProduct::getTable() . ' as p', 'p.id', '=', 'v.product_id');
        if ($type !== null) {
            $query->where('e.movement_type', '=', $type);
        }
        if ($since !== null) {
            $query->where('e.operational_at', '>=', $since);
        }
        $movement = $query->select(
            'e.movement_type',
            'e.quantity',
            'e.operational_at',
            'p.name as product_name',
            'v.sku',
        )
            ->orderByDesc('e.operational_at')
            ->orderByDesc('e.id')
            ->first();
        if ($movement === null) {
            return null;
        }
        $movement = $movement->toArray();

        $movement['movement_label'] = match ((string) $movement['movement_type']) {
            'receipt' => 'Réception',
            'issue' => 'Sortie',
            'adjustment' => 'Ajustement',
            'transfer_in' => 'Transfert entrant',
            'transfer_out' => 'Transfert sortant',
            'initial' => 'Stock initial',
            'reversal' => 'Annulation',
            default => (string) $movement['movement_type'],
        };

        return $movement;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchBalanceForUpdate(int $itemId, int $locationId, int $lotId): ?array
    {
        $row = InventoryRecord::table('inventory_stock_balances')
            ->where('item_id', '=', $itemId)
            ->where('location_id', '=', $locationId)
            ->where('lot_id', '=', $lotId)
            ->lockForUpdate()
            ->first();

        return $row?->toArray();
    }

    /** @return array<string, mixed>|null */
    private function firstOutOfStockItem(): ?array
    {
        $items = InventoryRecord::table('inventory_items as i')
            ->join(CatalogVariant::getTable() . ' as v', 'v.id', '=', 'i.variant_id')
            ->join(CatalogProduct::getTable() . ' as p', 'p.id', '=', 'v.product_id')
            ->where('i.status', '=', 'active')
            ->whereNull('i.archived_at')
            ->where('p.status', '=', 'active')
            ->where('p.kind', '=', 'goods')
            ->where('v.status', '=', 'active')
            ->select('i.id as item_id', 'p.name as product_name', 'v.sku')
            ->orderBy('p.name')
            ->orderBy('v.sku')
            ->get();
        $balances = InventoryRecord::table('inventory_stock_balances')
            ->select('item_id')
            ->selectRaw('SUM(physical_qty - reserved_qty) AS available_qty')
            ->groupBy('item_id')
            ->get();
        $availableByItem = [];
        foreach ($balances as $balance) {
            $availableByItem[(int) $balance->item_id] = (string) $balance->available_qty;
        }

        foreach ($items as $item) {
            $available = $availableByItem[(int) $item->item_id] ?? '0';
            if (InventoryQuantity::compare($available, '0') <= 0) {
                return [
                    'product_name' => $item->product_name,
                    'sku' => $item->sku,
                    'available_qty' => $available,
                ];
            }
        }

        return null;
    }

    private function assertTransactionOpen(): void
    {
        if (!$this->connection->inTransaction()) {
            throw new UnprocessableEntityException([
                'transaction' => ['Les soldes de stock doivent être modifiés dans une transaction active.'],
            ]);
        }
    }

    private function validateIdentifiers(int $itemId, int $locationId, int $lotId): void
    {
        $errors = Data::create([
            'item_id' => $itemId,
            'location_id' => $locationId,
            'lot_id' => $lotId,
        ])->validate([
            'item_id' => 'required|integer|min:1',
            'location_id' => 'required|integer|min:1',
            'lot_id' => 'required|integer|min:1',
        ]);
        if ($errors !== []) {
            throw new UnprocessableEntityException($errors);
        }
    }

    private function validateQuantity(string $quantity, bool $positive = false): void
    {
        $errors = Data::create(['quantity' => $quantity])
            ->validate(['quantity' => 'required|numeric' . ($positive ? '|positive' : '')]);
        if ($errors !== []) {
            throw new UnprocessableEntityException($errors);
        }
    }
}
