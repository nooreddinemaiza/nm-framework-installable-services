<?php

declare(strict_types=1);

namespace NM\Services\Articles\Services;

use Core\Exceptions\Http\ServiceNotInstalledException;
use Core\Services\IndependentServiceRegistry;

final class ArticleServiceState
{
    public function __construct(private readonly IndependentServiceRegistry $services)
    {
    }

    public function assertEnabled(): void
    {
        if ($this->services->status('articles') !== 'active') {
            throw new ServiceNotInstalledException('Le service Articles n’est pas actif.');
        }
    }
}
