<?php

declare(strict_types=1);

namespace NM\Services\SalesService\Sales\Domain;

use InvalidArgumentException;

final class SalesOrder
{
    /**
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed> $details
     */
    public function __construct(
        public readonly string $id,
        public readonly int $applicationId,
        public readonly ?int $tenantId,
        public readonly string $profile,
        public readonly string $status,
        public readonly int $version,
        public readonly Money $subtotal,
        public readonly Money $tax,
        public readonly Money $total,
        public readonly array $lines,
        public readonly array $details,
        public readonly ?string $publicReference = null,
    ) {
        if ($id === '' || $applicationId < 1 || ($tenantId !== null && $tenantId < 1) || $version < 1) {
            throw new InvalidArgumentException('Sales order identity or version is invalid.');
        }
        if (!SalesOrderState::isKnown($status)) {
            throw new InvalidArgumentException('Sales order status is invalid.');
        }
        if ($subtotal->currency !== $total->currency || $tax->currency !== $total->currency) {
            throw new InvalidArgumentException('Sales order amounts must use one currency.');
        }
    }
}
