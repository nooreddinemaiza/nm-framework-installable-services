<?php

declare(strict_types=1);

namespace NM\Services\SalesService\Sales;

final class SalesPlatform
{
    public function __construct(
        private readonly SalesApplicationRegistry $applications,
        private readonly SalesApiKeyService $apiKeys,
        private readonly SalesCartService $carts,
        private readonly SalesCheckoutService $checkout,
        private readonly SalesOrderService $orders,
        private readonly SalesOrderFinancialService $financials,
    ) {
    }

    public function applications(): SalesApplicationRegistry
    {
        return $this->applications;
    }

    public function apiKeys(): SalesApiKeyService
    {
        return $this->apiKeys;
    }

    public function carts(): SalesCartService
    {
        return $this->carts;
    }

    public function checkout(): SalesCheckoutService
    {
        return $this->checkout;
    }

    public function orders(): SalesOrderService
    {
        return $this->orders;
    }

    public function financials(): SalesOrderFinancialService
    {
        return $this->financials;
    }
}
