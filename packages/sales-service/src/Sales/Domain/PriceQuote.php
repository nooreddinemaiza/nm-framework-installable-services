<?php

declare(strict_types=1);

namespace NM\Services\SalesService\Sales\Domain;

final class PriceQuote
{
    /**
     * @param list<array{tax_mode: string, tax_basis_points: int, tax_minor: int}> $lineTaxes
     */
    public function __construct(
        public readonly Money $subtotal,
        public readonly Money $tax,
        public readonly Money $total,
        public readonly array $lineTaxes,
        public readonly string $taxMode,
        public readonly int $taxBasisPoints,
    ) {
    }

    /**
     * @return array{subtotal_minor: int, tax_minor: int, total_minor: int, tax_mode: string, tax_basis_points: int, rounding: string}
     */
    public function snapshot(): array
    {
        return [
            'subtotal_minor' => $this->subtotal->minorAmount,
            'tax_minor' => $this->tax->minorAmount,
            'total_minor' => $this->total->minorAmount,
            'tax_mode' => $this->taxMode,
            'tax_basis_points' => $this->taxBasisPoints,
            'rounding' => 'half-up-per-line',
        ];
    }
}
