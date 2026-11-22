<?php

declare(strict_types=1);

namespace NM\Services\SocialAuth\Social;

use Core\Exceptions\Http\ServiceNotInstalledException;
use Core\Services\IndependentServiceRegistry;

final class SocialAuthServiceState
{
    public function __construct(private readonly IndependentServiceRegistry $services)
    {
    }

    public function isEnabled(): bool
    {
        return $this->services->status('social-auth') === 'active';
    }

    public function assertEnabled(): void
    {
        if (!$this->isEnabled()) {
            throw new ServiceNotInstalledException('Le service d’authentification sociale n’est pas actif.');
        }
    }
}
