<?php

declare(strict_types=1);

namespace NM\Services\Quotes\Providers;

use Core\Container\AbstractServiceProvider;
use Core\Database\ConnectionManager;
use Core\Services\IndependentServiceRegistry;
use NM\Services\Quotes\Quotes\QuoteConfigurationStore;
use NM\Services\Quotes\Quotes\QuotePdfService;
use NM\Services\Quotes\Quotes\QuoteRepository;
use NM\Services\Quotes\Quotes\QuoteService;
use NM\Services\Quotes\Quotes\QuoteServiceState;

final class QuotesServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->singleton(QuoteServiceState::class, function (): QuoteServiceState {
            return new QuoteServiceState($this->app()->get(IndependentServiceRegistry::class));
        });
        $this->singleton(QuoteConfigurationStore::class, function (): QuoteConfigurationStore {
            return new QuoteConfigurationStore(
                $this->app()->get(ConnectionManager::class)->connection()
            );
        });
        $this->singleton(QuoteRepository::class, static fn(): QuoteRepository => new QuoteRepository());
        $this->singleton(QuoteService::class, function (): QuoteService {
            return new QuoteService(
                $this->app()->get(QuoteRepository::class),
                $this->app()->get(QuoteConfigurationStore::class)
            );
        });
        $this->singleton(QuotePdfService::class, function (): QuotePdfService {
            return new QuotePdfService($this->app()->get(QuoteRepository::class));
        });
    }
}
