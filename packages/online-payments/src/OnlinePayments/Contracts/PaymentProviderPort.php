<?php

declare(strict_types=1);

namespace NM\Services\OnlinePayments\OnlinePayments\Contracts;

use NM\Services\OnlinePayments\OnlinePayments\Domain\HostedPayment;
use NM\Services\OnlinePayments\OnlinePayments\Domain\PaymentCheckoutRequest;
use NM\Services\OnlinePayments\OnlinePayments\Domain\PaymentRefundResult;
use NM\Services\OnlinePayments\OnlinePayments\Domain\VerifiedPaymentNotification;

interface PaymentProviderPort
{
    public function code(): string;

    public function name(): string;

    /**
     * @param array<string, string> $credentials
     */
    public function createCheckout(PaymentCheckoutRequest $request, array $credentials): HostedPayment;

    /**
     * @param array<string, string> $credentials
     */
    /**
     * Verify the provider's signature and normalize a terminal provider status.
     * Never treat the browser return URL as payment confirmation.
     *
     * @param array<string, string> $headers
     * @param array<string, string> $credentials
     */
    public function verifyNotification(
        array $headers,
        string $rawBody,
        array $credentials
    ): VerifiedPaymentNotification;

    /**
     * @param array<string, string> $credentials
     */
    public function refund(
        string $providerReference,
        int $amountMinor,
        string $currency,
        string $idempotencyKey,
        array $credentials
    ): PaymentRefundResult;
}
