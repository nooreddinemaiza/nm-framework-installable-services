<?php

declare(strict_types=1);

namespace NM\Services\SalesService\Sales\Contracts;

use NM\Services\SalesService\Sales\Domain\Money;
use NM\Services\InventoryService\Inventory\Discount\DTO\DiscountResult;
use InvalidArgumentException;

final class CatalogItem
{
    public function __construct(
        public readonly string $reference,
        public readonly string $label,
        public readonly string $unit,
        public readonly Money $unitPrice,
        public readonly string $priceSource,
        public readonly ?Money $originalUnitPrice = null,
        public readonly ?DiscountResult $discount = null,
    ) {
        if ($reference === '' || $label === '' || $unit === '' || $priceSource === '') {
            throw new InvalidArgumentException('Catalog item fields cannot be empty.');
        }
        if ($unitPrice->minorAmount < 0) {
            throw new InvalidArgumentException('Catalog item price cannot be negative.');
        }
        if ($originalUnitPrice !== null
            && ($originalUnitPrice->minorAmount < $unitPrice->minorAmount
                || $originalUnitPrice->currency !== $unitPrice->currency)) {
            throw new InvalidArgumentException('Catalog item original price is invalid.');
        }
    }
}
