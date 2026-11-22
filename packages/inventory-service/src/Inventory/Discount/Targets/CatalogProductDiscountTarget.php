<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Inventory\Discount\Targets;

use NM\Services\InventoryService\Inventory\Discount\Contracts\DiscountTargetInterface;
use NM\Services\InventoryService\Models\CatalogProduct;

final class CatalogProductDiscountTarget implements DiscountTargetInterface
{
    public function targetType(): string
    {
        return 'product';
    }

    public function priority(): int
    {
        return 300;
    }

    public function exists(int $targetId): bool
    {
        return CatalogProduct::query()->where('id', '=', $targetId)->exists();
    }

    public function lock(int $targetId): bool
    {
        return CatalogProduct::query()
            ->where('id', '=', $targetId)
            ->lockForUpdate()
            ->first() !== null;
    }

    public function targetIdsForProduct(int $productId): array
    {
        return $this->exists($productId) ? [$productId] : [];
    }
}
