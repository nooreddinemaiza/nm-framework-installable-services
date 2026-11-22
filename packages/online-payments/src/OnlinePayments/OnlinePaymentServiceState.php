<?php

declare(strict_types=1);

namespace NM\Services\OnlinePayments\OnlinePayments;

use Core\Exceptions\Http\ServiceNotInstalledException;
use Core\Services\IndependentServiceRegistry;

final class OnlinePaymentServiceState
{
    public function __construct(private readonly IndependentServiceRegistry $services)
    {
    }

    public function assertReady(): void
    {
        foreach (['sales-service', 'online-payments'] as $serviceId) {
            if (!$this->isEnabled($serviceId)) {
                throw new ServiceNotInstalledException("Le service requis [{$serviceId}] n’est pas actif.");
            }
        }
    }

    public function isEnabled(string $serviceId): bool
    {
        return $this->services->status($serviceId) === 'active';
    }
}
