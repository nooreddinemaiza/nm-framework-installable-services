<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Inventory\Discount\Targets;

use NM\Services\InventoryService\Inventory\Discount\Contracts\DiscountTargetInterface;
use NM\Services\InventoryService\Models\CatalogCategory;
use NM\Services\InventoryService\Models\CatalogProduct;

final class CatalogCategoryDiscountTarget implements DiscountTargetInterface
{
    public function targetType(): string
    {
        return 'category';
    }

    public function priority(): int
    {
        return 200;
    }

    public function exists(int $targetId): bool
    {
        return CatalogCategory::query()->where('id', '=', $targetId)->exists();
    }

    public function lock(int $targetId): bool
    {
        return CatalogCategory::query()
            ->where('id', '=', $targetId)
            ->lockForUpdate()
            ->first() !== null;
    }

    public function targetIdsForProduct(int $productId): array
    {
        $row = CatalogProduct::query()
            ->where('id', '=', $productId)
            ->whereNotNull('category_id')
            ->select('category_id')
            ->first();

        return $row === null ? [] : [(int) $row->category_id];
    }
}
