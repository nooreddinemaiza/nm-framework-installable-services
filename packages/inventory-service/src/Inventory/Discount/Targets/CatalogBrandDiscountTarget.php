<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Inventory\Discount\Targets;

use NM\Services\InventoryService\Inventory\Discount\Contracts\DiscountTargetInterface;
use NM\Services\InventoryService\Models\CatalogBrand;
use NM\Services\InventoryService\Models\CatalogProduct;

final class CatalogBrandDiscountTarget implements DiscountTargetInterface
{
    public function targetType(): string
    {
        return 'brand';
    }

    public function priority(): int
    {
        return 100;
    }

    public function exists(int $targetId): bool
    {
        return CatalogBrand::query()->where('id', '=', $targetId)->exists();
    }

    public function lock(int $targetId): bool
    {
        return CatalogBrand::query()
            ->where('id', '=', $targetId)
            ->lockForUpdate()
            ->first() !== null;
    }

    public function targetIdsForProduct(int $productId): array
    {
        $row = CatalogProduct::query()
            ->where('id', '=', $productId)
            ->whereNotNull('brand_id')
            ->select('brand_id')
            ->first();

        return $row === null ? [] : [(int) $row->brand_id];
    }
}
