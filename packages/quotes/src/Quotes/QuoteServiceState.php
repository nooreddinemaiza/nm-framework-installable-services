<?php

declare(strict_types=1);

namespace NM\Services\Quotes\Quotes;

use Core\Exceptions\Http\ServiceNotInstalledException;
use Core\Services\IndependentServiceRegistry;

final class QuoteServiceState
{
    public function __construct(private readonly IndependentServiceRegistry $services)
    {
    }

    public function assertEnabled(): void
    {
        foreach (['inventory-service', 'quotes'] as $serviceId) {
            if ($this->services->status($serviceId) !== 'active') {
                throw new ServiceNotInstalledException("Le service requis [{$serviceId}] n’est pas actif.");
            }
        }
    }
}
