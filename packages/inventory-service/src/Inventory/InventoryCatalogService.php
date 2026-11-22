<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Inventory;

use NM\Services\InventoryService\Models\CatalogProduct;
use NM\Services\InventoryService\Models\CatalogVariant;
use NM\Services\InventoryService\Models\InventoryRecord;
use Core\Exceptions\Http\UnprocessableEntityException;
use Core\Support\Data\Data;

final class InventoryCatalogService
{
    /**
     * @return list<array{id: int|string, code: string, name: string}>
     */
    public function units(): array
    {
        return array_map(
            static fn(InventoryRecord $unit): array => $unit->toArray(),
            InventoryRecord::table('inventory_units')
                ->select('id', 'code', 'name')
                ->orderBy('name')
                ->orderBy('code')
                ->get()
        );
    }

    public function createForVariant(
        int $variantId,
        ?int $unitId = null,
        bool $allowInactiveProduct = false,
        string $trackingMode = 'none',
        bool $allowNegativeStock = false,
    ): int {
        $input = Data::create(['tracking_mode' => $trackingMode, 'variant_id' => $variantId]);
        $errors = $input->validate([
            'tracking_mode' => 'required|in:none,lot',
            'variant_id' => 'required|integer|min:1',
        ]);
        if ($errors !== []) {
            throw new UnprocessableEntityException($errors);
        }
        $variantQuery = CatalogVariant::query()
            ->join(CatalogProduct::getTable() . ' as p', 'p.id', '=', 'catalog_variants.product_id')
            ->where('catalog_variants.id', '=', $variantId)
            ->where('p.kind', '=', 'goods');
        if (!$allowInactiveProduct) {
            $variantQuery->where('catalog_variants.status', '=', 'active')
                ->where('p.status', '=', 'active');
        }
        $variant = $variantQuery->select('catalog_variants.id')->first();
        if ($variant === null) {
            throw new UnprocessableEntityException([
                'variant_id' => ['Seuls les produits physiques actifs peuvent être reliés à l’inventaire.'],
            ]);
        }

        $existing = InventoryRecord::table('inventory_items')
            ->where('variant_id', '=', $variantId)
            ->select('id')
            ->first();
        if ($existing !== null) {
            return (int) $existing->id;
        }

        if ($unitId === null) {
            $unitId = $this->defaultUnitId();
        } elseif (!InventoryRecord::table('inventory_units')->where('id', '=', $unitId)->exists()) {
            throw new UnprocessableEntityException([
                'stock_unit_id' => ['L’unité sélectionnée n’existe pas.'],
            ]);
        }

        $now = date('Y-m-d H:i:s');
        $itemId = InventoryRecord::table('inventory_items')->insertGetId([
            'variant_id' => $variantId,
            'reference_unit_id' => $unitId,
            'type' => 'stock',
            'status' => 'active',
            'track_inventory' => 1,
            'tracking_mode' => $trackingMode,
            'track_expiration' => 0,
            'allow_decimal_quantity' => 1,
            'quantity_scale' => 6,
            'allow_negative_stock' => $allowNegativeStock ? 1 : 0,
            'min_qty' => null,
            'max_qty' => null,
            'picking_strategy' => null,
            'archived_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($trackingMode === 'none') {
            InventoryRecord::table('inventory_item_lots')->insert([
                'item_id' => $itemId,
                'lot_code' => 'DEFAULT',
                'is_sentinel' => 1,
                'status' => 'available',
                'manufactured_at' => null,
                'expires_at' => null,
                'source_reference' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $itemId;
    }

    private function defaultUnitId(): int
    {
        $unit = InventoryRecord::table('inventory_units')
            ->where('code', '=', 'PCS')
            ->select('id')
            ->first();
        if ($unit !== null) {
            return (int) $unit->id;
        }

        $now = date('Y-m-d H:i:s');
        return InventoryRecord::table('inventory_units')->insertGetId([
            'code' => 'PCS',
            'name' => 'Pièce',
            'precision_scale' => 6,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
