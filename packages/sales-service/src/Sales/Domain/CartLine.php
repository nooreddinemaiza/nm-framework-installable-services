<?php

declare(strict_types=1);

namespace NM\Services\SalesService\Sales\Domain;

use InvalidArgumentException;

final class CartLine
{
    /**
     * @param array<string, scalar|null|array> $modifiers
     */
    public function __construct(
        public readonly string $itemReference,
        public readonly string $label,
        public readonly string $quantity,
        public readonly string $unit,
        public readonly Money $unitPrice,
        public readonly array $modifiers = [],
        public readonly string $priceSource = 'catalog',
        public readonly ?string $pricedAt = null,
    ) {
        if ($itemReference === '' || strlen($itemReference) > 190) {
            throw new InvalidArgumentException('Item reference must contain 1-190 bytes.');
        }
        if (preg_match('/^.{1,190}$/us', $label) !== 1) {
            throw new InvalidArgumentException('Item label must contain 1-190 characters.');
        }
        DecimalQuantity::toScaledInteger($quantity);
        if ($unit === '' || strlen($unit) > 30) {
            throw new InvalidArgumentException('Unit must contain 1-30 bytes.');
        }
        if ($unitPrice->minorAmount < 0) {
            throw new InvalidArgumentException('Unit price cannot be negative.');
        }
        if ($priceSource === '' || strlen($priceSource) > 80) {
            throw new InvalidArgumentException('Price source must contain 1-80 bytes.');
        }
        json_encode($modifiers, JSON_THROW_ON_ERROR);
    }

    public function total(): Money
    {
        return $this->unitPrice->multiplyQuantity($this->quantity);
    }
}
