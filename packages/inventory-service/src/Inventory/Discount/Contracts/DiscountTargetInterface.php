<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Inventory\Discount\Contracts;

interface DiscountTargetInterface
{
    public function targetType(): string;

    public function priority(): int;

    public function exists(int $targetId): bool;

    /**
     * Lock the target row for the current transaction and report whether it exists.
     */
    public function lock(int $targetId): bool;

    /** @return list<int> */
    public function targetIdsForProduct(int $productId): array;
}
