<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Providers;

use Core\Container\AbstractServiceProvider;
use Core\Database\ConnectionManager;
use Core\Filesystem\FileReaderInterface;
use InvalidArgumentException;
use NM\Services\InventoryService\Catalog\CatalogMediaService;
use NM\Services\InventoryService\Catalog\CatalogService;
use NM\Services\InventoryService\Catalog\Contracts\ProductReferenceInterface;
use NM\Services\InventoryService\Catalog\ProductUsageRegistry;
use NM\Services\InventoryService\Inventory\Discount\Contracts\DiscountRepositoryInterface;
use NM\Services\InventoryService\Inventory\Discount\DiscountTargetRegistry;
use NM\Services\InventoryService\Inventory\Discount\Repositories\DiscountRepository;
use NM\Services\InventoryService\Inventory\Discount\Services\DiscountCalculator;
use NM\Services\InventoryService\Inventory\Discount\Services\DiscountResolver;
use NM\Services\InventoryService\Inventory\Discount\Services\DiscountService;
use NM\Services\InventoryService\Inventory\Discount\Targets\CatalogBrandDiscountTarget;
use NM\Services\InventoryService\Inventory\Discount\Targets\CatalogCategoryDiscountTarget;
use NM\Services\InventoryService\Inventory\Discount\Targets\CatalogProductDiscountTarget;
use NM\Services\InventoryService\Inventory\Discount\Validators\DiscountValidator;
use NM\Services\InventoryService\Inventory\InventoryBalanceService;
use NM\Services\InventoryService\Inventory\InventoryCatalogService;
use NM\Services\InventoryService\Inventory\InventoryConfig;
use NM\Services\InventoryService\Inventory\InventoryConfigurationStore;
use NM\Services\InventoryService\Inventory\InventoryCountService;
use NM\Services\InventoryService\Inventory\InventoryFacade;
use NM\Services\InventoryService\Inventory\InventoryMovementRuleValidator;
use NM\Services\InventoryService\Inventory\InventoryMovementService;
use NM\Services\InventoryService\Inventory\InventoryOutboxDrainer;
use NM\Services\InventoryService\Inventory\InventoryProductUsageChecker;
use NM\Services\InventoryService\Inventory\InventoryReservationService;
use NM\Services\InventoryService\Inventory\InventoryStockService;
use NM\Services\InventoryService\Inventory\InventoryTransactionBoundary;
use NM\Services\InventoryService\Services\InventoryServiceState;

final class InventoryServiceServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $settings = $this->app()->config('services.inventory-service', []);
        if (!is_array($settings)) {
            throw new InvalidArgumentException('Inventory service configuration must be an array.');
        }
        $inventoryConfig = $settings['inventory'] ?? [];
        if (!is_array($inventoryConfig)) {
            throw new InvalidArgumentException('Inventory configuration must be an array.');
        }

        $this->singleton(ProductUsageRegistry::class, static fn(): ProductUsageRegistry => new ProductUsageRegistry());
        $this->singleton(InventoryCatalogService::class, static fn(): InventoryCatalogService => new InventoryCatalogService());
        $this->singleton(CatalogMediaService::class, function (): CatalogMediaService {
            return new CatalogMediaService(
                $this->app()->get(ConnectionManager::class)->connection(),
                $this->app()->files(),
                $this->app()->get(FileReaderInterface::class)
            );
        });
        $this->singleton(CatalogService::class, function () use ($settings): CatalogService {
            $catalog = $settings['catalog'] ?? [];
            if (!is_array($catalog)) {
                throw new InvalidArgumentException('Catalog configuration must be an array.');
            }

            return new CatalogService(
                $this->app()->get(ConnectionManager::class)->connection(),
                $this->app()->get(ProductUsageRegistry::class),
                $this->app()->get(InventoryCatalogService::class),
                $this->app()->get(CatalogMediaService::class),
                (string) ($catalog['default_status'] ?? 'active'),
                (int) ($catalog['category_max_depth'] ?? 10),
                strtoupper((string) ($catalog['sku_prefix'] ?? 'CAT')),
                (int) ($catalog['sku_padding'] ?? 8),
                strtoupper((string) $this->app()->env(
                    'APP_CURRENCY',
                    $this->app()->config('APP.APP_CURRENCY', 'MAD')
                )),
            );
        });
        $this->singleton(
            ProductReferenceInterface::class,
            fn(): ProductReferenceInterface => $this->app()->get(CatalogService::class)
        );

        $this->singleton(InventoryConfigurationStore::class, function (): InventoryConfigurationStore {
            return new InventoryConfigurationStore(
                $this->app()->get(ConnectionManager::class)->connection()
            );
        });
        $this->singleton(InventoryConfig::class, function () use ($inventoryConfig): InventoryConfig {
            return InventoryConfig::from(array_replace(
                $inventoryConfig,
                $this->app()->get(InventoryConfigurationStore::class)->runtimeOverrides()
            ));
        });
        $this->singleton(InventoryTransactionBoundary::class, function (): InventoryTransactionBoundary {
            return new InventoryTransactionBoundary(
                $this->app()->get(ConnectionManager::class)->connection()
            );
        });
        $this->singleton(DiscountTargetRegistry::class, static function (): DiscountTargetRegistry {
            $targets = new DiscountTargetRegistry();
            $targets->register(new CatalogBrandDiscountTarget());
            $targets->register(new CatalogCategoryDiscountTarget());
            $targets->register(new CatalogProductDiscountTarget());
            return $targets;
        });
        $this->singleton(DiscountRepositoryInterface::class, static fn(): DiscountRepositoryInterface => new DiscountRepository());
        $this->singleton(DiscountValidator::class, static fn(): DiscountValidator => new DiscountValidator());
        $this->singleton(DiscountResolver::class, function (): DiscountResolver {
            return new DiscountResolver(
                $this->app()->get(DiscountRepositoryInterface::class),
                $this->app()->get(DiscountTargetRegistry::class),
            );
        });
        $this->singleton(DiscountCalculator::class, static fn(): DiscountCalculator => new DiscountCalculator());
        $this->singleton(DiscountService::class, function (): DiscountService {
            return new DiscountService(
                $this->app()->get(DiscountRepositoryInterface::class),
                $this->app()->get(DiscountTargetRegistry::class),
                $this->app()->get(DiscountValidator::class),
                $this->app()->get(DiscountResolver::class),
                $this->app()->get(DiscountCalculator::class),
                $this->app()->get(InventoryTransactionBoundary::class),
                $this->app()->get(CatalogService::class),
            );
        });
        $this->singleton(InventoryProductUsageChecker::class, static fn(): InventoryProductUsageChecker => new InventoryProductUsageChecker());
        $this->get(ProductUsageRegistry::class)->register(
            $this->get(InventoryProductUsageChecker::class)
        );
        $this->singleton(InventoryBalanceService::class, function (): InventoryBalanceService {
            return new InventoryBalanceService(
                $this->app()->get(ConnectionManager::class)->connection()
            );
        });
        $this->singleton(InventoryMovementRuleValidator::class, static fn(): InventoryMovementRuleValidator => new InventoryMovementRuleValidator());
        $this->singleton(InventoryMovementService::class, function (): InventoryMovementService {
            return new InventoryMovementService(
                $this->app()->get(InventoryTransactionBoundary::class),
                $this->app()->get(InventoryBalanceService::class),
                $this->app()->get(InventoryMovementRuleValidator::class)
            );
        });
        $this->singleton(InventoryReservationService::class, function (): InventoryReservationService {
            return new InventoryReservationService(
                $this->app()->get(ConnectionManager::class)->connection(),
                $this->app()->get(InventoryBalanceService::class)
            );
        });
        $this->singleton(InventoryOutboxDrainer::class, static fn(): InventoryOutboxDrainer => new InventoryOutboxDrainer(
            static function (string $eventType, array $payload, array $row): void {
                unset($eventType, $payload, $row);
            }
        ));
        $this->singleton(InventoryStockService::class, function (): InventoryStockService {
            return new InventoryStockService(
                $this->app()->get(InventoryBalanceService::class),
                $this->app()->get(InventoryReservationService::class),
                $this->app()->get(InventoryMovementService::class),
                $this->app()->get(InventoryTransactionBoundary::class),
                $this->app()->get(InventoryMovementRuleValidator::class)
            );
        });
        $this->singleton(InventoryCountService::class, function (): InventoryCountService {
            return new InventoryCountService(
                $this->app()->get(ConnectionManager::class)->connection(),
                $this->app()->get(InventoryBalanceService::class),
                $this->app()->get(InventoryTransactionBoundary::class),
                $this->app()->get(InventoryMovementRuleValidator::class)
            );
        });
        $this->singleton(InventoryFacade::class, function (): InventoryFacade {
            return new InventoryFacade(
                $this->app()->get(InventoryMovementService::class),
                $this->app()->get(InventoryStockService::class),
                $this->app()->get(InventoryCountService::class),
                $this->app()->get(InventoryTransactionBoundary::class),
                $this->app()->get(DiscountService::class)
            );
        });
        $this->singleton(InventoryServiceState::class, fn(): InventoryServiceState => new InventoryServiceState(
            $this->app()->get(\Core\Services\IndependentServiceRegistry::class)
        ));
    }
}
