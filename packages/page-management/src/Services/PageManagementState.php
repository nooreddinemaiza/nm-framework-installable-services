<?php

declare(strict_types=1);

namespace NM\Services\PageManagement\Services;

use Core\Exceptions\Http\ServiceNotInstalledException;
use Core\Services\IndependentServiceRegistry;

final class PageManagementState
{
    public function __construct(private readonly IndependentServiceRegistry $services)
    {
    }

    public function isEnabled(): bool
    {
        return $this->services->status('page-management') === 'active';
    }

    public function assertEnabled(): void
    {
        if (!$this->isEnabled()) {
            throw new ServiceNotInstalledException('Le service Gestion des pages n’est pas actif.');
        }
    }
}
