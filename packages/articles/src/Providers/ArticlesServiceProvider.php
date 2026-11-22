<?php

declare(strict_types=1);

namespace NM\Services\Articles\Providers;

use Core\Services\IndependentServiceRegistry;
use Core\Container\AbstractServiceProvider;
use NM\Services\Articles\Services\ArticleConfigurationStore;
use NM\Services\Articles\Services\ArticleService;
use NM\Services\Articles\Services\ArticleServiceState;

final class ArticlesServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->singleton(
            ArticleConfigurationStore::class,
            static fn(): ArticleConfigurationStore => new ArticleConfigurationStore()
        );
        $this->singleton(
            ArticleService::class,
            fn(): ArticleService => new ArticleService($this->app()->get(ArticleConfigurationStore::class))
        );
        $this->singleton(ArticleServiceState::class, function (): ArticleServiceState {
            return new ArticleServiceState($this->app()->get(IndependentServiceRegistry::class));
        });
    }
}
