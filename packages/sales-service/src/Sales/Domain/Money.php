<?php

declare(strict_types=1);

namespace NM\Services\SalesService\Sales\Domain;

use InvalidArgumentException;
use OverflowException;

final class Money
{
    public function __construct(
        public readonly int $minorAmount,
        public readonly string $currency,
    ) {
        if (!preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new InvalidArgumentException('Currency must be an uppercase three-letter code.');
        }
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);
        if (($other->minorAmount > 0 && $this->minorAmount > PHP_INT_MAX - $other->minorAmount)
            || ($other->minorAmount < 0 && $this->minorAmount < PHP_INT_MIN - $other->minorAmount)) {
            throw new OverflowException('Money addition overflow.');
        }

        return new self($this->minorAmount + $other->minorAmount, $this->currency);
    }

    public function multiplyQuantity(string $quantity): self
    {
        $scaledQuantity = DecimalQuantity::toScaledInteger($quantity);
        if ($this->minorAmount === PHP_INT_MIN
            || ($this->minorAmount !== 0 && $scaledQuantity > intdiv(PHP_INT_MAX, abs($this->minorAmount)))) {
            throw new OverflowException('Money multiplication overflow.');
        }

        $product = $this->minorAmount * $scaledQuantity;
        $whole = intdiv($product, DecimalQuantity::SCALE);
        $remainder = abs($product % DecimalQuantity::SCALE);

        if ($remainder >= intdiv(DecimalQuantity::SCALE, 2)) {
            $whole += $product < 0 ? -1 : 1;
        }

        return new self($whole, $this->currency);
    }

    public function format(): string
    {
        $negative = $this->minorAmount < 0;
        $digits = $negative
            ? substr((string) $this->minorAmount, 1)
            : (string) $this->minorAmount;
        $digits = str_pad($digits, 3, '0', STR_PAD_LEFT);
        $formatted = substr($digits, 0, -2) . '.' . substr($digits, -2);

        return ($negative ? '-' : '') . $formatted . ' ' . $this->currency;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException('Cannot combine amounts in different currencies.');
        }
    }
}
