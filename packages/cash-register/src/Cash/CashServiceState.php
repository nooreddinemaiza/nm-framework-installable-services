<?php

declare(strict_types=1);

namespace NM\Services\CashRegister\Cash;

use Core\Exceptions\Http\ServiceNotInstalledException;
use Core\Services\IndependentServiceRegistry;

final class CashServiceState
{
    public function __construct(private readonly IndependentServiceRegistry $services)
    {
    }

    public function assertReady(): void
    {
        foreach (['sales-service', 'cash-register'] as $serviceId) {
            if ($this->services->status($serviceId) !== 'active') {
                throw new ServiceNotInstalledException("Le service requis [{$serviceId}] n’est pas actif.");
            }
        }
    }
}
