<?php

declare(strict_types=1);

namespace NM\Services\PageManagement\Providers;

use App\Seo\SitemapProviderRegistry;
use Core\Cache\CacheInterface;
use Core\Container\AbstractServiceProvider;
use Core\Database\ConnectionManager;
use Core\Events\EventDispatcher;
use Core\Services\IndependentServiceRegistry;
use NM\Services\PageManagement\Pages\PageContentRegistry;
use NM\Services\PageManagement\Pages\PageManagementService;
use NM\Services\PageManagement\Seo\PageManagementSitemapProvider;
use NM\Services\PageManagement\Services\PageManagementState;

final class PageManagementServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->singleton(PageContentRegistry::class, static fn(): PageContentRegistry => new PageContentRegistry());
        $this->singleton(PageManagementState::class, function (): PageManagementState {
            return new PageManagementState($this->app()->get(IndependentServiceRegistry::class));
        });
        $this->singleton(PageManagementService::class, function (): PageManagementService {
            return new PageManagementService(
                $this->app()->get(ConnectionManager::class)->connection(),
                $this->app()->get(PageContentRegistry::class),
                $this->app()->get(CacheInterface::class),
                $this->app()->get(EventDispatcher::class),
            );
        });
        $this->singleton(PageManagementSitemapProvider::class, function (): PageManagementSitemapProvider {
            return new PageManagementSitemapProvider(
                $this->app()->get(PageManagementService::class),
                $this->app()->get(PageManagementState::class),
            );
        });

        $this->get(SitemapProviderRegistry::class)->register(
            'page-management',
            function (): ?PageManagementSitemapProvider {
                if (!$this->app()->environment()->getBool('DB_ON', false)
                    || !$this->app()->get(PageManagementState::class)->isEnabled()) {
                    return null;
                }

                return $this->app()->get(PageManagementSitemapProvider::class);
            }
        );
    }
}
