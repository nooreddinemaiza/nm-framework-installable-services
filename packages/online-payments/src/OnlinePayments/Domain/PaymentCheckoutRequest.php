<?php

declare(strict_types=1);

namespace NM\Services\OnlinePayments\OnlinePayments\Domain;

final class PaymentCheckoutRequest
{
    public function __construct(
        public readonly string $orderReference,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly string $idempotencyKey,
        public readonly string $merchantReference,
        public readonly string $successUrl,
        public readonly string $cancelUrl,
        public readonly string $notificationUrl,
    ) {
    }
}
