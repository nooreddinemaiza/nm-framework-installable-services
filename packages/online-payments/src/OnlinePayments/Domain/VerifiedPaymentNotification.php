<?php

declare(strict_types=1);

namespace NM\Services\OnlinePayments\OnlinePayments\Domain;

final class VerifiedPaymentNotification
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $providerReference,
        public readonly string $merchantReference,
        public readonly string $status,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly string $type = 'payment',
    ) {
    }
}
