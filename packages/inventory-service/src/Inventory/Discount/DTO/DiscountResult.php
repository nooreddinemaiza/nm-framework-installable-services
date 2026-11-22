<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Inventory\Discount\DTO;

final class DiscountResult
{
    /**
     * @param list<array<string, int|string|null>> $appliedDiscounts
     */
    public function __construct(
        public readonly string $originalPrice,
        public readonly string $finalPrice,
        public readonly string $totalSaving,
        public readonly string $totalSavingPercentage,
        public readonly string $currency,
        public readonly string $calculatedAt,
        public readonly array $appliedDiscounts,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'original_price' => $this->originalPrice,
            'final_price' => $this->finalPrice,
            'total_saving' => $this->totalSaving,
            'total_saving_percentage' => $this->totalSavingPercentage,
            'currency' => $this->currency,
            'calculated_at' => $this->calculatedAt,
            'applied_discounts' => $this->appliedDiscounts,
        ];
    }
}
