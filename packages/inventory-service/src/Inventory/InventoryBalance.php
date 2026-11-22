<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Inventory;

final readonly class InventoryBalance
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public int $id,
        public int $itemId,
        public int $locationId,
        public int $lotId,
        public string $physicalQty,
        public string $reservedQty,
        public array $raw = [],
    ) {
    }

    public function availableQty(): InventoryQuantity
    {
        return new InventoryQuantity($this->physicalQty)->subtract(new InventoryQuantity($this->reservedQty));
    }

    public function availableForReservation(string $quantity): bool
    {
        return InventoryQuantity::compare($this->availableQty()->toString(), $quantity) >= 0;
    }
}
