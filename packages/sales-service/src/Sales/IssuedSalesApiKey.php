<?php

declare(strict_types=1);

namespace NM\Services\SalesService\Sales;

final class IssuedSalesApiKey
{
    /**
     * The plaintext key is returned only at issuance and must not be persisted.
     *
     * @param list<string> $scopes
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $secret,
        public readonly array $scopes,
        public readonly ?string $expiresAt,
    ) {
    }
}
