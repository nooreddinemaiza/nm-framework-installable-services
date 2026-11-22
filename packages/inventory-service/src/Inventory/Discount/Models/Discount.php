<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Inventory\Discount\Models;

final class Discount
{
    public function __construct(
        public readonly int $id,
        public readonly string $targetType,
        public readonly int $targetId,
        public readonly string $discountType,
        public readonly string $value,
        public readonly ?string $currency,
        public readonly string $startsAt,
        public readonly string $endsAt,
        public readonly bool $isActive,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            targetType: (string) $row['target_type'],
            targetId: (int) $row['target_id'],
            discountType: (string) $row['discount_type'],
            value: (string) $row['value'],
            currency: isset($row['currency']) ? (string) $row['currency'] : null,
            startsAt: (string) $row['starts_at'],
            endsAt: (string) $row['ends_at'],
            isActive: (bool) $row['is_active'],
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );
    }

    /** @return array<string, int|string|bool|null> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'target_type' => $this->targetType,
            'target_id' => $this->targetId,
            'discount_type' => $this->discountType,
            'value' => $this->value,
            'currency' => $this->currency,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
