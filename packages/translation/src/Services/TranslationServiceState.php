<?php

declare(strict_types=1);

namespace NM\Services\Translation\Services;

use Core\Exceptions\Http\ServiceNotInstalledException;
use Core\Services\IndependentServiceRegistry;

final class TranslationServiceState
{
    public function __construct(private readonly IndependentServiceRegistry $services)
    {
    }

    public function assertEnabled(): void
    {
        if ($this->services->status('translation') !== 'active') {
            throw new ServiceNotInstalledException('Le service de traduction n’est pas actif.');
        }
    }
}
