<?php

declare(strict_types=1);

namespace NM\Services\Messaging\Providers;

use Core\Container\AbstractServiceProvider;
use Core\Services\IndependentServiceRegistry;
use NM\Services\Messaging\Services\MessagingServiceState;

final class MessagingServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->singleton(MessagingServiceState::class, function (): MessagingServiceState {
            return new MessagingServiceState($this->app()->get(IndependentServiceRegistry::class));
        });
    }
}
