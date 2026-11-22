<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Inventory\Discount\Contracts;

use NM\Services\InventoryService\Inventory\Discount\Models\Discount;

interface DiscountRepositoryInterface
{
    /** @param array<string, mixed> $data */
    public function create(array $data): Discount;

    public function find(int $id, bool $forUpdate = false): ?Discount;

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): Discount;

    /** @return list<Discount> */
    public function forTarget(string $targetType, int $targetId): array;

    public function hasOverlap(string $targetType, int $targetId, string $startsAt, string $endsAt, ?int $exceptId = null): bool;

    /** @return list<Discount> */
    public function findApplicable(string $targetType, int $targetId, string $at): array;
}
