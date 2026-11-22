<?php

declare(strict_types=1);

namespace NM\Services\OnlinePayments\Providers;

use NM\Services\OnlinePayments\OnlinePayments\OnlinePaymentService;
use NM\Services\OnlinePayments\OnlinePayments\OnlinePaymentServiceState;
use NM\Services\OnlinePayments\OnlinePayments\PaymentCredentialCipher;
use NM\Services\OnlinePayments\OnlinePayments\PaymentProviderRegistry;
use NM\Services\SalesService\Sales\SalesApplicationRegistry;
use NM\Services\SalesService\Sales\SalesOrderFinancialService;
use Core\Container\AbstractServiceProvider;
use Core\Database\ConnectionManager;
use Core\Services\IndependentServiceRegistry;
use InvalidArgumentException;

final class OnlinePaymentsServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->singleton(PaymentProviderRegistry::class, static fn(): PaymentProviderRegistry => new PaymentProviderRegistry());
        $this->singleton(PaymentCredentialCipher::class, static fn(): PaymentCredentialCipher => new PaymentCredentialCipher(
            is_string(app()->env('ONLINE_PAYMENTS_ENCRYPTION_KEY'))
                ? (string) app()->env('ONLINE_PAYMENTS_ENCRYPTION_KEY')
                : null
        ));
        $this->singleton(OnlinePaymentServiceState::class, function (): OnlinePaymentServiceState {
            return new OnlinePaymentServiceState($this->app()->get(IndependentServiceRegistry::class));
        });
        $this->singleton(OnlinePaymentService::class, function (): OnlinePaymentService {
            $salesConfig = $this->app()->config('services.sales-service', []);
            if (!is_array($salesConfig)) {
                throw new InvalidArgumentException('La configuration Sales doit être un tableau.');
            }

            return new OnlinePaymentService(
                $this->app()->get(OnlinePaymentServiceState::class),
                $this->app()->get(ConnectionManager::class)->connection(),
                $this->app()->get(PaymentProviderRegistry::class),
                $this->app()->get(PaymentCredentialCipher::class),
                $this->app()->get(SalesOrderFinancialService::class),
                $this->app()->get(SalesApplicationRegistry::class),
                (string) ($salesConfig['storefront_code'] ?? 'storefront'),
                rtrim((string) $this->app()->env('APP_URL', ''), '/'),
            );
        });
    }
}
