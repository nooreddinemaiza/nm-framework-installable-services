<?php

declare(strict_types=1);

namespace NM\Services\OnlinePayments\OnlinePayments\Domain;

use DateTimeImmutable;

final class HostedPayment
{
    public function __construct(
        public readonly string $providerReference,
        public readonly string $redirectUrl,
        public readonly ?DateTimeImmutable $expiresAt,
    ) {
    }
}
