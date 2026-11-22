<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Catalog\Contracts;

interface ProductUsageCheckerInterface
{
    public function isUsed(int $productId, ?int $variantId = null): bool;
}
