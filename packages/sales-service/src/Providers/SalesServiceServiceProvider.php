<?php

declare(strict_types=1);

namespace NM\Services\SalesService\Providers;

use NM\Services\SalesService\Sales\SalesApiKeyService;
use NM\Services\SalesService\Sales\SalesApplicationRegistry;
use NM\Services\SalesService\Sales\SalesCartRepository;
use NM\Services\SalesService\Sales\SalesCartService;
use NM\Services\SalesService\Sales\SalesCheckoutService;
use NM\Services\SalesService\Sales\SalesOrderRepository;
use NM\Services\SalesService\Sales\SalesOrderFinancialService;
use NM\Services\SalesService\Sales\SalesOrderService;
use NM\Services\SalesService\Sales\SalesStatisticsService;
use NM\Services\SalesService\Sales\SalesInventoryFinalizer;
use NM\Services\SalesService\Sales\SalesPricingService;
use NM\Services\SalesService\Sales\SalesPlatform;
use NM\Services\SalesService\Sales\SalesServiceState;
use NM\Services\SalesService\Sales\Adapters\LocalAvailabilityAdapter;
use NM\Services\SalesService\Sales\Adapters\LocalCatalogAdapter;
use NM\Services\InventoryService\Inventory\InventoryFacade;
use NM\Services\SalesService\Sales\Contracts\AvailabilityPort;
use NM\Services\SalesService\Sales\Contracts\CatalogPort;
use NM\Services\SalesService\View\Components\SalesDashboard;
use NM\Services\SalesService\View\Components\SalesOrderConfirmation;
use Core\Container\AbstractServiceProvider;
use Core\Database\ConnectionManager;
use Core\Services\IndependentServiceRegistry;
use Core\View\ViewFactory;
use InvalidArgumentException;

final class SalesServiceServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $salesConfig = $this->app()->config('services.sales-service', []);
        if (!is_array($salesConfig)) {
            throw new InvalidArgumentException('La configuration Sales doit être un tableau.');
        }

        $this->singleton(SalesApplicationRegistry::class, function (): SalesApplicationRegistry {
            return new SalesApplicationRegistry(
                $this->app()->get(ConnectionManager::class)->connection(),
                $this->app()->get(SalesServiceState::class),
            );
        });

        $this->singleton(SalesApiKeyService::class, function (): SalesApiKeyService {
            return new SalesApiKeyService(
                $this->app()->get(ConnectionManager::class)->connection(),
                $this->app()->get(SalesServiceState::class),
            );
        });

        $this->singleton(SalesServiceState::class, function (): SalesServiceState {
            return new SalesServiceState($this->app()->get(IndependentServiceRegistry::class));
        });

        if (!$this->has(CatalogPort::class)) {
            $this->singleton(CatalogPort::class, fn (): CatalogPort => new LocalCatalogAdapter(
                $this->app()->get(ConnectionManager::class)->connection(),
                $this->app()->get(InventoryFacade::class)->discounts(),
            ));
        }
        if (!$this->has(AvailabilityPort::class)) {
            $this->singleton(AvailabilityPort::class, fn (): AvailabilityPort => new LocalAvailabilityAdapter(
                $this->app()->get(ConnectionManager::class)->connection()
            ));
        }

        $this->singleton(SalesCartRepository::class, function (): SalesCartRepository {
            return new SalesCartRepository(
                $this->app()->get(ConnectionManager::class)->connection()
            );
        });

        $this->singleton(SalesCartService::class, function () use ($salesConfig): SalesCartService {
            foreach (['max_cart_items', 'max_quantity', 'anonymous_cart_ttl'] as $key) {
                if (!isset($salesConfig[$key]) || !is_int($salesConfig[$key]) || $salesConfig[$key] < 1) {
                    throw new InvalidArgumentException("La configuration Sales [{$key}] doit être un entier positif.");
                }
            }
            if ($salesConfig['max_cart_items'] > 500
                || $salesConfig['max_quantity'] > 1_000_000
                || $salesConfig['anonymous_cart_ttl'] > 31_536_000) {
                throw new InvalidArgumentException('Les limites Sales dépassent les valeurs maximales autorisées.');
            }

            return new SalesCartService(
                $this->app()->get(ConnectionManager::class)->connection(),
                $this->app()->get(SalesCartRepository::class),
                $this->app()->get(SalesApplicationRegistry::class),
                $this->app()->get(CatalogPort::class),
                $this->app()->get(AvailabilityPort::class),
                $salesConfig['max_cart_items'],
                $salesConfig['max_quantity'],
                $salesConfig['anonymous_cart_ttl'],
            );
        });

        $this->singleton(SalesPricingService::class, static fn (): SalesPricingService => new SalesPricingService());
        $this->singleton(SalesStatisticsService::class, function (): SalesStatisticsService {
            return new SalesStatisticsService(
                $this->app()->get(ConnectionManager::class)->connection(),
            );
        });

        $this->singleton(SalesOrderRepository::class, function (): SalesOrderRepository {
            return new SalesOrderRepository(
                $this->app()->get(ConnectionManager::class)->connection(),
                $this->app()->get(SalesCartRepository::class),
            );
        });

        $this->singleton(SalesOrderFinancialService::class, function () use ($salesConfig): SalesOrderFinancialService {
            return new SalesOrderFinancialService(
                $this->app()->get(ConnectionManager::class)->connection(),
                $this->app()->get(SalesApplicationRegistry::class),
                $this->app()->get(SalesOrderRepository::class),
                $this->app()->get(SalesCartRepository::class),
                (string) ($salesConfig['storefront_code'] ?? 'storefront'),
            );
        });

        $this->singleton(SalesCheckoutService::class, function (): SalesCheckoutService {
            return new SalesCheckoutService(
                $this->app()->get(ConnectionManager::class)->connection(),
                $this->app()->get(SalesCartRepository::class),
                $this->app()->get(SalesApplicationRegistry::class),
                $this->app()->get(CatalogPort::class),
                $this->app()->get(AvailabilityPort::class),
                $this->app()->get(SalesPricingService::class),
                $this->app()->get(SalesOrderRepository::class),
            );
        });

        $this->singleton(SalesOrderService::class, function (): SalesOrderService {
            return new SalesOrderService(
                $this->app()->get(ConnectionManager::class)->connection(),
                $this->app()->get(SalesApplicationRegistry::class),
                $this->app()->get(SalesOrderRepository::class),
                $this->app()->get(SalesInventoryFinalizer::class),
                $this->app()->get(SalesOrderFinancialService::class),
            );
        });

        $this->singleton(SalesInventoryFinalizer::class, function (): SalesInventoryFinalizer {
            return new SalesInventoryFinalizer(
                $this->app()->get(ConnectionManager::class)->connection(),
                $this->app()->get(InventoryFacade::class),
            );
        });

        $this->singleton(SalesPlatform::class, function (): SalesPlatform {
            return new SalesPlatform(
                $this->app()->get(SalesApplicationRegistry::class),
                $this->app()->get(SalesApiKeyService::class),
                $this->app()->get(SalesCartService::class),
                $this->app()->get(SalesCheckoutService::class),
                $this->app()->get(SalesOrderService::class),
                $this->app()->get(SalesOrderFinancialService::class),
            );
        });
    }

    public function boot(): void
    {
        $components = $this->app()->get(ViewFactory::class)->components();
        $components->register('sales-service-dashboard', SalesDashboard::class);
        $components->register('sales-service-order-confirmation', SalesOrderConfirmation::class);
    }
}
