<?php

use App\Models\User;
use Core\Filesystem\FileInterface;
use Core\Http\Request;
use Core\Http\Response;
use Core\Middleware\AuthMiddleware;
use Core\Middleware\CsrfMiddleware;
use Core\Routing\Router;
use Core\Services\IndependentServiceRegistry;
use Core\Support\Asset\AssetManager;
use Core\Services\Registry\IndependentServiceManager;
use NM\Services\Quotes\Controllers\QuoteConfigurationController;
use NM\Services\Quotes\Controllers\QuoteController;
use NM\Services\Quotes\Quotes\QuoteConfigurationStore;
use NM\Services\Quotes\Quotes\QuotePdfService;
use NM\Services\Quotes\Quotes\QuoteRepository;
use NM\Services\Quotes\Quotes\QuoteService;
use NM\Services\Quotes\Quotes\QuoteServiceState;

$resolveController = static fn(bool $withView = false): QuoteController => new QuoteController(
    app()->session(),
    app()->get(QuoteService::class),
    app()->get(QuoteRepository::class),
    app()->get(QuotePdfService::class),
    app()->get(QuoteServiceState::class),
    $withView ? app()->viewFactory() : null
);
$resolveConfigurationController = static fn(): QuoteConfigurationController => new QuoteConfigurationController(
    app()->session(),
    app()->get(QuoteConfigurationStore::class),
    app()->get(IndependentServiceManager::class),
);

$router->middleware([AuthMiddleware::class, CsrfMiddleware::class])
    ->post('/dashboard/quotes/configuration', fn(Request $request) => $resolveConfigurationController()->save($request));

$router->prefix('dashboard/')
    ->middleware(['throthle:500', AuthMiddleware::class, 'access:' . User::MODERATOR_LEVEL])
    ->group(function (Router $router) use ($resolveController): void {
        $router->prefix('quotes')
            ->withoutMiddleware('access:' . User::MODERATOR_LEVEL)
            ->group(function (Router $router) use ($resolveController): void {
                $router->get('', fn(Request $request) => $resolveController(true)->index($request));
                $router->get('/data', fn(Request $request) => $resolveController()->data($request));
                $router->post('/status', fn(Request $request) => $resolveController()->updateStatus($request))
                    ->middleware(CsrfMiddleware::class);
                $router->get('/pdf', fn(Request $request) => $resolveController()->pdf($request));
                $router->get('/csv', fn(Request $request) => $resolveController()->csv($request));
            });
    });

$router->get('/api/quotes/catalog-search', fn(Request $request) => $resolveController()->search($request));
$router->post('/api/quotes/submit', fn(Request $request) => $resolveController()->submit($request))
    ->middleware(CsrfMiddleware::class);

$router->get('/dashboard/service-assets/quotes/{filename}', static function (
    Request $request,
    string $filename
): Response {
    $definition = app()->get(IndependentServiceRegistry::class)->get('quotes');
    $resources = $definition?->componentPath('resources');
    if ($resources === null) {
        throw new \Core\Exceptions\Http\NotFoundHttpException();
    }

    app()->get(FileInterface::class)->setPath(
        'quotes-service-assets',
        $resources . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'scripts'
    );

    return app()->get(AssetManager::class)->respond($request, 'quotes-service-assets', $filename);
});
