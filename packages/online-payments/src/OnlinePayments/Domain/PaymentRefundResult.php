<?php

declare(strict_types=1);

namespace NM\Services\OnlinePayments\OnlinePayments\Domain;

final class PaymentRefundResult
{
    public function __construct(
        public readonly string $providerRefundReference,
        public readonly string $status,
    ) {
    }
}
