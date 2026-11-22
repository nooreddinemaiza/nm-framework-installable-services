<?php

use Core\Database\ConnectionManager;
use Core\Filesystem\FileInterface;
use Core\Http\Request;
use Core\Http\Response;
use Core\Middleware\AuthMiddleware;
use Core\Middleware\CsrfMiddleware;
use Core\Routing\Router;
use Core\Services\IndependentServiceRegistry;
use Core\Support\Asset\AssetManager;
use NM\Services\SalesService\Controllers\SalesDashboardController;
use NM\Services\SalesService\Controllers\StorefrontCheckoutController;
use NM\Services\SalesService\Sales\Contracts\CatalogPort;
use NM\Services\SalesService\Sales\SalesApplicationRegistry;
use NM\Services\SalesService\Sales\SalesOrderRepository;
use NM\Services\SalesService\Sales\SalesOrderService;
use NM\Services\SalesService\Sales\SalesServiceState;
use NM\Services\SalesService\Sales\SalesStatisticsService;
use NM\Services\SalesService\Sales\SalesPlatform;

$resolveSalesController = static fn(): SalesDashboardController => new SalesDashboardController(
    app()->get(ConnectionManager::class)->connection(),
    app()->viewFactory(),
    app()->session(),
    app()->get(SalesOrderRepository::class),
    app()->get(SalesOrderService::class),
    app()->get(SalesApplicationRegistry::class),
    app()->get(SalesStatisticsService::class),
    app()->get(SalesServiceState::class),
);
$dispatchSales = static function (callable $action): Response {
    if (!app()->environment()->getBool('DB_ON', false)) {
        return new Response('Le service Ventes nécessite une base de données configurée.', 503);
    }

    return $action();
};

$router->prefix('dashboard/')
    ->middleware(AuthMiddleware::class)
    ->group(function (Router $router) use ($resolveSalesController, $dispatchSales): void {
        $router->prefix('/sales')
            ->group(function (Router $router) use ($resolveSalesController, $dispatchSales): void {
                $router->get('', fn(Request $request) => $dispatchSales(
                    static fn() => $resolveSalesController()->index($request)
                ));
                $router->get('/statistics', fn(Request $request) => $dispatchSales(
                    static fn() => $resolveSalesController()->statistics($request)
                ));
                $router->get('/statistics/export', fn(Request $request) => $dispatchSales(
                    static fn() => $resolveSalesController()->statisticsExport($request)
                ));
                $router->get('/orders/pdf', fn(Request $request) => $dispatchSales(
                    static fn() => $resolveSalesController()->orderPdf($request)
                ));
                $router->get('/orders/search', fn(Request $request) => $dispatchSales(
                    static fn() => $resolveSalesController()->search($request)
                ));
                foreach ([
                    '/storefront/setup' => 'setupStorefront',
                    '/orders/transition' => 'transition',
                    '/orders/create' => 'createOrder',
                    '/orders/update' => 'updateOrder',
                    '/orders/delete' => 'deleteOrders',
                    '/orders/email' => 'emailOrder',
                ] as $path => $method) {
                    $router->middleware(CsrfMiddleware::class)->post(
                        $path,
                        fn(Request $request) => $dispatchSales(
                            static fn() => $resolveSalesController()->{$method}($request)
                        )
                    );
                }
            });
    });

$resolveStorefrontController = static fn(): StorefrontCheckoutController => new StorefrontCheckoutController(
    app()->viewFactory(),
    app()->session(),
    app()->get(SalesApplicationRegistry::class),
    app()->get(SalesPlatform::class),
    app()->get(CatalogPort::class),
    app()->get(SalesOrderRepository::class),
    app()->get(SalesServiceState::class),
    (string) app()->config('services.sales-service.storefront_code', 'storefront'),
    strtoupper((string) app()->env('APP_CURRENCY', app()->config('APP.APP_CURRENCY', 'MAD'))),
);
$dispatchStorefront = static function (callable $action): Response {
    if (!app()->environment()->getBool('DB_ON', false)) {
        return new Response('Le service Ventes nécessite une base de données configurée.', 503);
    }

    return $action();
};

$router->get('/checkout', fn() => $dispatchStorefront(
    static fn() => $resolveStorefrontController()->checkout()
));
$router->middleware(CsrfMiddleware::class)->post('/api/storefront/cart-pricing', fn(Request $request) => $dispatchStorefront(
    static fn() => $resolveStorefrontController()->pricingPreview($request)
));
$router->middleware(CsrfMiddleware::class)->post('/checkout/order', fn(Request $request) => $dispatchStorefront(
    static fn() => $resolveStorefrontController()->placeOrder($request)
));
$router->get('/suivi-commande', fn() => $dispatchStorefront(
    static fn() => $resolveStorefrontController()->orderStatusPage()
));
$router->middleware(CsrfMiddleware::class)->post('/api/storefront/order-status', fn(Request $request) => $dispatchStorefront(
    static fn() => $resolveStorefrontController()->lookupOrder($request)
));
$router->get('/checkout/confirmation/{orderId}', fn(Request $request, string $orderId) => $dispatchStorefront(
    static fn() => $resolveStorefrontController()->confirmation($orderId)
));

$router->get('/dashboard/service-assets/sales-service/{filename}', static function (
    Request $request,
    string $filename
): Response {
    $definition = app()->get(IndependentServiceRegistry::class)->get('sales-service');
    $resources = $definition?->componentPath('resources');
    if ($resources === null) {
        throw new \Core\Exceptions\Http\NotFoundHttpException();
    }

    app()->get(FileInterface::class)->setPath(
        'sales-service-assets',
        $resources . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'scripts'
    );

    return app()->get(AssetManager::class)->respond($request, 'sales-service-assets', $filename);
});
