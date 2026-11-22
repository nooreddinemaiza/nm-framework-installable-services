<?php

declare(strict_types=1);

namespace NM\Services\SalesService\Sales;

use NM\Services\SalesService\Sales\Domain\Cart;
use NM\Services\SalesService\Sales\Domain\Money;
use NM\Services\SalesService\Sales\Domain\PriceQuote;
use InvalidArgumentException;

final class SalesPricingService
{
    /**
     * @param array<string, mixed> $configuration
     */
    public function quote(Cart $cart, array $configuration): PriceQuote
    {
        $mode = $configuration['tax_mode'] ?? null;
        $basisPoints = $configuration['tax_basis_points'] ?? null;
        if (!in_array($mode, ['none', 'exclusive'], true)
            || !is_int($basisPoints)
            || $basisPoints < 0
            || $basisPoints > 10_000
            || ($mode === 'none' && $basisPoints !== 0)) {
            throw new InvalidArgumentException('Pricing requires an explicit tax_mode and valid tax_basis_points.');
        }

        $subtotal = $cart->total();
        $tax = new Money(0, $cart->currency);
        $lineTaxes = [];
        foreach ($cart->lines as $line) {
            $lineTax = $mode === 'none' ? 0 : self::taxFor($line->total()->minorAmount, $basisPoints);
            $tax = $tax->add(new Money($lineTax, $cart->currency));
            $lineTaxes[] = [
                'tax_mode' => $mode,
                'tax_basis_points' => $basisPoints,
                'tax_minor' => $lineTax,
            ];
        }

        return new PriceQuote(
            subtotal: $subtotal,
            tax: $tax,
            total: $subtotal->add($tax),
            lineTaxes: $lineTaxes,
            taxMode: $mode,
            taxBasisPoints: $basisPoints,
        );
    }

    private static function taxFor(int $amountMinor, int $basisPoints): int
    {
        $whole = intdiv($amountMinor, 10_000) * $basisPoints;
        $remainder = ($amountMinor % 10_000) * $basisPoints;

        return $whole + intdiv($remainder + 5_000, 10_000);
    }
}
