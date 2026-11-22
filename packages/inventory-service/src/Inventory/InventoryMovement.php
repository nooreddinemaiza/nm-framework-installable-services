<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Inventory;

final readonly class InventoryMovement
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string $movementType,
        public int $itemId,
        public int $locationId,
        public int $lotId,
        public string $quantity,
        public array $context = [],
    ) {
    }

    public function signedQuantity(): InventoryQuantity
    {
        return InventoryQuantity::signedFor($this->movementType, $this->quantity);
    }
}
