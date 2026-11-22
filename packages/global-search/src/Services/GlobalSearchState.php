<?php

declare(strict_types=1);

namespace NM\Services\GlobalSearch\Services;

use Core\Exceptions\Http\ServiceNotInstalledException;
use Core\Services\IndependentServiceRegistry;

final class GlobalSearchState
{
    public function __construct(private readonly IndependentServiceRegistry $services)
    {
    }

    public function isActive(string $serviceId): bool
    {
        return $this->services->status($serviceId) === 'active';
    }

    public function isEnabled(): bool
    {
        return $this->isActive('global-search');
    }

    public function assertEnabled(): void
    {
        if (!$this->isEnabled()) {
            throw new ServiceNotInstalledException('Le service de recherche globale n’est pas actif.');
        }
    }
}
