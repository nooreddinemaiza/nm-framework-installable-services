<?php

declare(strict_types=1);

namespace NM\Services\GlobalSearch\Providers;

use Core\Application\Application;
use Core\Container\AbstractServiceProvider;
use Core\Database\ConnectionManager;
use Core\Services\IndependentServiceRegistry;
use NM\Services\GlobalSearch\Database\GlobalSearchBootstrapper;
use NM\Services\GlobalSearch\Search\GlobalSearchService;
use NM\Services\GlobalSearch\Services\GlobalSearchState;

final class GlobalSearchServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->singleton(GlobalSearchState::class, function (): GlobalSearchState {
            return new GlobalSearchState($this->app()->get(IndependentServiceRegistry::class));
        });
        $this->singleton(GlobalSearchService::class, function (): GlobalSearchService {
            return new GlobalSearchService($this->app()->get(GlobalSearchState::class));
        });
        $this->singleton(GlobalSearchBootstrapper::class, function (): GlobalSearchBootstrapper {
            return new GlobalSearchBootstrapper(
                $this->app()->get(ConnectionManager::class),
                $this->app()->get(GlobalSearchState::class),
            );
        });

        $application = $this->app();
        if (!$application instanceof Application) {
            throw new \RuntimeException('Global Search nécessite le cycle de démarrage du framework.');
        }
        $application->bootstrapper('service.global-search', function (): void {
            $bootstrapper = $this->get(GlobalSearchBootstrapper::class);
            $bootstrapper();
        });
    }
}
