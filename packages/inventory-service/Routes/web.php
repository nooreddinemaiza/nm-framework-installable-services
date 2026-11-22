<?php

use App\Models\User;
use Core\Database\ConnectionManager;
use Core\Filesystem\FileInterface;
use Core\Http\JsonResponse;
use Core\Http\Request;
use Core\Http\Response;
use Core\Middleware\AuthMiddleware;
use Core\Middleware\CsrfMiddleware;
use Core\Routing\Router;
use Core\Services\IndependentServiceRegistry;
use Core\Support\Asset\AssetManager;
use NM\Services\InventoryService\Controllers\CatalogController;
use NM\Services\InventoryService\Controllers\InventoryController;
use NM\Services\InventoryService\Inventory\Discount\Services\DiscountService;
use NM\Services\InventoryService\Inventory\InventoryCatalogService;
use NM\Services\InventoryService\Inventory\InventoryConfig;
use NM\Services\InventoryService\Inventory\InventoryConfigurationStore;
use NM\Services\InventoryService\Inventory\InventoryFacade;
use NM\Services\InventoryService\Catalog\CatalogMediaService;
use NM\Services\InventoryService\Catalog\CatalogService;

$catalogController = static fn(): CatalogController => new CatalogController(
    app()->get(CatalogService::class),
    app()->get(CatalogMediaService::class),
    app()->get(InventoryCatalogService::class),
    app()->viewFactory(),
    app()->session(),
    app()->get(DiscountService::class)
);
$dispatch = static function (callable $action) use ($catalogController): Response {
    if (!app()->environment()->getBool('DB_ON', false)) {
        return new Response('Le catalogue nécessite une base de données configurée.', 503);
    }

    return $action($catalogController());
};

$router->prefix('dashboard/')
    ->middleware(['throthle:500', AuthMiddleware::class, 'access:' . User::MODERATOR_LEVEL])
    ->group(function (Router $router) use ($dispatch): void {
        $router->prefix('/catalog')
            ->group(function (Router $router) use ($dispatch): void {
                $router->get('', fn(Request $request) => $dispatch(static fn(CatalogController $controller) => $controller->index($request)));
                $router->get('/search', fn(Request $request) => $dispatch(static fn(CatalogController $controller) => $controller->search($request)));
                $router->get('/media', fn(Request $request) => $dispatch(static fn(CatalogController $controller) => $controller->media($request)));
                $router->get('/categories/search', fn(Request $request) => $dispatch(static fn(CatalogController $controller) => $controller->searchCategories($request)));
                $router->get('/brands/search', fn(Request $request) => $dispatch(static fn(CatalogController $controller) => $controller->searchBrands($request)));
                $router->get('/categories', fn(Request $request) => $dispatch(static fn(CatalogController $controller) => $controller->categories($request)));
                $router->get('/brands', fn(Request $request) => $dispatch(static fn(CatalogController $controller) => $controller->brands($request)));
                foreach ([
                    '/products/delete' => 'delete',
                    '/create' => 'create',
                    '/update' => 'update',
                    '/status' => 'status',
                    '/trending' => 'trending',
                    '/delete' => 'delete',
                    '/category' => 'category',
                    '/category/update' => 'updateCategory',
                    '/category/delete' => 'deleteCategory',
                    '/brand' => 'brand',
                    '/brand/update' => 'updateBrand',
                    '/brand/delete' => 'deleteBrand',
                    '/media/upload' => 'uploadMedia',
                    '/media/link' => 'linkMedia',
                    '/media/delete' => 'deleteMedia',
                ] as $path => $method) {
                    $router->middleware(CsrfMiddleware::class)->post(
                        $path,
                        fn(Request $request) => $dispatch(
                            static fn(CatalogController $controller) => $controller->{$method}($request)
                        )
                    );
                }
            });

        $router->prefix('/inventory')
            ->group(function (Router $router): void {
                $resolveController = static fn(): InventoryController => new InventoryController(
                    app()->get(InventoryFacade::class),
                    app()->get(InventoryConfig::class),
                    app()->viewFactory(),
                    app()->session(),
                    app()->get(ConnectionManager::class)->connection(),
                    app()->get(InventoryCatalogService::class),
                    app()->get(InventoryConfigurationStore::class)
                );
                $dispatchInventory = static function (callable $action, bool $json = false) use ($resolveController): Response {
                    if (!app()->environment()->getBool('DB_ON', false)) {
                        $message = 'Le module Inventory nécessite une base de données configurée.';
                        return $json
                            ? new JsonResponse(['success' => false, 'message' => $message], 503)
                            : new Response($message, 503);
                    }
                    return $action($resolveController());
                };
                $router->get('', fn() => $dispatchInventory(static fn(InventoryController $controller) => $controller->view()));
                $router->get('/data', fn() => $dispatchInventory(static fn(InventoryController $controller) => $controller->data(), true));
                $router->middleware(CsrfMiddleware::class)->post('/configuration', fn(Request $request) => $dispatchInventory(static fn(InventoryController $controller) => $controller->saveConfiguration($request), true));
                $router->post('/movement', fn(Request $request) => $dispatchInventory(static fn(InventoryController $controller) => $controller->movement($request), true));
                $router->post('/reserve', fn(Request $request) => $dispatchInventory(static fn(InventoryController $controller) => $controller->reserve($request), true));
                $router->post('/reserve/release', fn(Request $request) => $dispatchInventory(static fn(InventoryController $controller) => $controller->releaseReservation($request), true));
                $router->post('/reserve/consume', fn(Request $request) => $dispatchInventory(static fn(InventoryController $controller) => $controller->consumeReservation($request), true));
                $router->post('/count/start', fn(Request $request) => $dispatchInventory(static fn(InventoryController $controller) => $controller->startCount($request), true));
                $router->post('/count/reconcile', fn(Request $request) => $dispatchInventory(static fn(InventoryController $controller) => $controller->reconcileCount($request), true));
                $router->post('/catalog/units', fn(Request $request) => $dispatchInventory(static fn(InventoryController $controller) => $controller->createUnit($request), true));
                $router->post('/catalog/items', fn(Request $request) => $dispatchInventory(static fn(InventoryController $controller) => $controller->createItem($request), true));
                $router->post('/catalog/lots', fn(Request $request) => $dispatchInventory(static fn(InventoryController $controller) => $controller->createLot($request), true));
                $router->post('/warehouses', fn(Request $request) => $dispatchInventory(static fn(InventoryController $controller) => $controller->createWarehouse($request), true));
                $router->get('/discounts/target', fn(Request $request) => $dispatchInventory(static fn(InventoryController $controller) => $controller->discountsForTarget($request), true));
                $router->middleware(CsrfMiddleware::class)->post('/discounts/save', fn(Request $request) => $dispatchInventory(static fn(InventoryController $controller) => $controller->saveDiscount($request), true));
                $router->middleware(CsrfMiddleware::class)->post('/discounts/status', fn(Request $request) => $dispatchInventory(static fn(InventoryController $controller) => $controller->setDiscountStatus($request), true));
                $router->post('/discounts/calculate', fn(Request $request) => $dispatchInventory(static fn(InventoryController $controller) => $controller->calculateDiscount($request), true));
            });
    });

$router->get('/', fn() => $dispatch(static fn(CatalogController $controller) => $controller->publicHome()));
$router->get('/boutique', fn(Request $request) => $dispatch(static fn(CatalogController $controller) => $controller->publicStorefront($request)));
$router->get('/boutique/results', fn(Request $request) => $dispatch(static fn(CatalogController $controller) => $controller->publicStorefrontResults($request)));
$router->get('/categories', fn() => $dispatch(static fn(CatalogController $controller) => $controller->publicCategories()));
$router->get('/brands', fn() => $dispatch(static fn(CatalogController $controller) => $controller->publicBrands()));
$router->get('/tags', fn() => $dispatch(static fn(CatalogController $controller) => $controller->publicTags()));
$router->get('/tags/{slug}', fn(Request $request, string $slug) => $dispatch(static fn(CatalogController $controller) => $controller->publicTag($slug)));
$router->get('/products/{slug}', fn(Request $request, string $slug) => $dispatch(static fn(CatalogController $controller) => $controller->publicProduct($request, $slug)));
$router->get('/categories/{slug}', fn(Request $request, string $slug) => $dispatch(static fn(CatalogController $controller) => $controller->publicCategory($request, $slug)));
$router->get('/brands/{slug}', fn(Request $request, string $slug) => $dispatch(static fn(CatalogController $controller) => $controller->publicBrand($request, $slug)));
$router->get('/product-media/{id}', fn(Request $request, string $id) => $dispatch(static fn(CatalogController $controller) => $controller->publicMedia($request, $id)));

$router->get('/dashboard/service-assets/inventory-service/{filename}', static function (
    Request $request,
    string $filename
): Response {
    $definition = app()->get(IndependentServiceRegistry::class)->get('inventory-service');
    $resources = $definition?->componentPath('resources');
    if ($resources === null) {
        throw new \Core\Exceptions\Http\NotFoundHttpException();
    }
    app()->get(FileInterface::class)->setPath(
        'inventory-service-assets',
        $resources . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'scripts'
    );
    return app()->get(AssetManager::class)->respond($request, 'inventory-service-assets', $filename);
});
