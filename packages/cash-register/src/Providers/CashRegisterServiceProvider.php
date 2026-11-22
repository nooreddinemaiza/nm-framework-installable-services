<?php

declare(strict_types=1);

namespace NM\Services\CashRegister\Providers;

use Core\Container\AbstractServiceProvider;
use Core\Database\ConnectionManager;
use Core\Services\IndependentServiceRegistry;
use NM\Services\CashRegister\Cash\CashRegisterService;
use NM\Services\CashRegister\Cash\CashServiceState;
use NM\Services\SalesService\Sales\SalesOrderFinancialService;

final class CashRegisterServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->singleton(CashServiceState::class, function (): CashServiceState {
            return new CashServiceState($this->app()->get(IndependentServiceRegistry::class));
        });
        $this->singleton(CashRegisterService::class, function (): CashRegisterService {
            return new CashRegisterService(
                $this->app()->get(CashServiceState::class),
                $this->app()->get(ConnectionManager::class)->connection(),
                $this->app()->get(SalesOrderFinancialService::class)
            );
        });
    }
}
