<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Inventory\Discount\Services;

use NM\Services\InventoryService\Inventory\Discount\DiscountTargetRegistry;
use NM\Services\InventoryService\Inventory\Discount\Models\Discount;
use NM\Services\InventoryService\Inventory\Discount\Contracts\DiscountRepositoryInterface;
use Core\Support\Date;
use DateTimeImmutable;
use DateTimeZone;

final class DiscountResolver
{
    public function __construct(
        private readonly DiscountRepositoryInterface $repository,
        private readonly DiscountTargetRegistry $targets,
    ) {
    }

    /**
     * Resolve applicable discounts for a Catalog product and return them in configured target order.
     *
     * @return list<Discount>
     */
    public function resolveForProduct(int $productId, ?DateTimeImmutable $at = null): array
    {
        $at ??= Date::now('UTC');
        $instant = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $resolved = [];

        foreach ($this->targets->all() as $target) {
            $targetIds = array_values(array_unique($target->targetIdsForProduct($productId)));
            sort($targetIds, SORT_NUMERIC);
            foreach ($targetIds as $targetId) {
                if ($targetId < 1 || !$target->exists($targetId)) {
                    continue;
                }
                foreach ($this->repository->findApplicable($target->targetType(), $targetId, $instant) as $discount) {
                    $resolved[] = ['priority' => $target->priority(), 'discount' => $discount];
                }
            }
        }

        usort($resolved, static function (array $left, array $right): int {
            return ($left['priority'] <=> $right['priority'])
                ?: ($left['discount']->targetType <=> $right['discount']->targetType)
                ?: ($left['discount']->targetId <=> $right['discount']->targetId)
                ?: ($left['discount']->id <=> $right['discount']->id);
        });

        return array_map(static fn (array $entry): Discount => $entry['discount'], $resolved);
    }
}
