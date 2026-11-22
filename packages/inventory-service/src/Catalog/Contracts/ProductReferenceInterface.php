<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Catalog\Contracts;

interface ProductReferenceInterface
{
    /** @return array<string, mixed>|null */
    public function product(int $productId): ?array;

    /** @return array<string, mixed>|null */
    public function variant(int $variantId): ?array;
}
