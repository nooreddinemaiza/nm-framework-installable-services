<?php

declare(strict_types=1);

namespace NM\Services\SalesService\Sales\Domain;

use InvalidArgumentException;
use OverflowException;

final class DecimalQuantity
{
    public const SCALE = 1_000_000;

    private function __construct()
    {
    }

    public static function toScaledInteger(string $quantity): int
    {
        if (!preg_match('/^(0|[1-9][0-9]{0,8})(?:\.([0-9]{1,6}))?$/D', $quantity, $matches)) {
            throw new InvalidArgumentException('Quantity must be a positive decimal with at most six fractional digits.');
        }

        $whole = (int) $matches[1];
        $fraction = (int) str_pad($matches[2] ?? '', 6, '0');
        if ($whole > 0 && $whole > intdiv(PHP_INT_MAX - $fraction, self::SCALE)) {
            throw new OverflowException('Quantity is too large.');
        }

        $scaled = $whole * self::SCALE + $fraction;
        if ($scaled < 1) {
            throw new InvalidArgumentException('Quantity must be greater than zero.');
        }

        return $scaled;
    }
}
