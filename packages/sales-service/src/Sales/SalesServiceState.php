<?php

declare(strict_types=1);

namespace NM\Services\SalesService\Sales;

use Core\Exceptions\Http\ServiceNotInstalledException;
use Core\Services\IndependentServiceRegistry;

final class SalesServiceState
{
    public function __construct(private readonly IndependentServiceRegistry $services)
    {
    }

    public function assertEnabled(): void
    {
        if (!$this->isEnabled('sales-service')) {
            throw new ServiceNotInstalledException('Le service Ventes n’est pas actif.');
        }
    }

    public function isEnabled(string $serviceId): bool
    {
        return $this->services->status($serviceId) === 'active';
    }
}
