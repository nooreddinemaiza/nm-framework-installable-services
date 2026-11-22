<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Inventory;

use NM\Services\InventoryService\Catalog\Contracts\ProductUsageCheckerInterface;
use NM\Services\InventoryService\Models\CatalogVariant;

final class InventoryProductUsageChecker implements ProductUsageCheckerInterface
{
    public function isUsed(int $productId, ?int $variantId = null): bool
    {
        $query = CatalogVariant::table('catalog_variants as v')
            ->join('inventory_items as i', 'i.variant_id', '=', 'v.id')
            ->where('v.product_id', '=', $productId);
        if ($variantId !== null) {
            $query->where('v.id', '=', $variantId);
        }

        return $query->exists();
    }
}
