<?php

declare(strict_types=1);

namespace NM\Services\Messaging\Services;

use Core\Exceptions\Http\ServiceNotInstalledException;
use Core\Services\IndependentServiceRegistry;

final class MessagingServiceState
{
    public function __construct(private readonly IndependentServiceRegistry $services)
    {
    }

    public function assertEnabled(): void
    {
        if ($this->services->status('messaging') !== 'active') {
            throw new ServiceNotInstalledException("Le service de messagerie n'est pas installé ou activé.");
        }
    }
}
