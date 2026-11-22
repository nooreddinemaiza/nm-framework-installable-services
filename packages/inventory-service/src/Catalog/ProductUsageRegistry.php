<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Catalog;

use NM\Services\InventoryService\Catalog\Contracts\ProductUsageCheckerInterface;

final class ProductUsageRegistry
{
    /** @var list<ProductUsageCheckerInterface> */
    private array $checkers = [];

    public function register(ProductUsageCheckerInterface $checker): void
    {
        $this->checkers[] = $checker;
    }

    public function isUsed(int $productId, ?int $variantId = null): bool
    {
        foreach ($this->checkers as $checker) {
            if ($checker->isUsed($productId, $variantId)) {
                return true;
            }
        }

        return false;
    }
}
