<?php

declare(strict_types=1);

namespace NM\Services\SalesService\Sales\Domain;

use InvalidArgumentException;

final class Cart
{
    /**
     * @param list<CartLine> $lines
     */
    public function __construct(
        public readonly string $id,
        public readonly int $applicationId,
        public readonly ?int $tenantId,
        public readonly string $profile,
        public readonly string $currency,
        public readonly int $version = 1,
        public readonly array $lines = [],
    ) {
        if ($id === '' || strlen($id) > 80) {
            throw new InvalidArgumentException('Cart identifier is invalid.');
        }
        if ($applicationId < 1 || ($tenantId !== null && $tenantId < 1) || $version < 1) {
            throw new InvalidArgumentException('Cart context or version is invalid.');
        }
        if (!preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new InvalidArgumentException('Cart currency is invalid.');
        }
        foreach ($lines as $line) {
            if (!$line instanceof CartLine || $line->unitPrice->currency !== $currency) {
                throw new InvalidArgumentException('Cart lines must use the cart currency.');
            }
        }
    }

    public function add(CartLine $line, int $maxLines = 100): self
    {
        if ($line->unitPrice->currency !== $this->currency) {
            throw new InvalidArgumentException('Cart line currency does not match the cart.');
        }
        if (count($this->lines) >= $maxLines) {
            throw new InvalidArgumentException('Cart line limit reached.');
        }

        return new self(
            $this->id,
            $this->applicationId,
            $this->tenantId,
            $this->profile,
            $this->currency,
            $this->version + 1,
            [...$this->lines, $line],
        );
    }

    public function total(): Money
    {
        $total = new Money(0, $this->currency);
        foreach ($this->lines as $line) {
            $total = $total->add($line->total());
        }

        return $total;
    }
}
