<?php

use App\Models\User;
use Core\Filesystem\FileInterface;
use Core\Http\Request;
use Core\Http\Response;
use Core\Middleware\AuthMiddleware;
use Core\Routing\Router;
use Core\Services\IndependentServiceRegistry;
use Core\Support\Asset\AssetManager;
use NM\Services\GlobalSearch\Controllers\SearchController;
use NM\Services\GlobalSearch\Search\GlobalSearchService;

$resolveController = static fn(): SearchController => new SearchController(
    app()->get(GlobalSearchService::class),
    app()->viewFactory(),
    app()->session()
);

$router->get('/api/search', fn(Request $request) => $resolveController()->publicApi($request));
$router->get('/search/results', fn() => $resolveController()->publicResults());

$router->prefix('dashboard/')
    ->middleware(['throthle:500', AuthMiddleware::class, 'access:' . User::MODERATOR_LEVEL])
    ->group(function (Router $router) use ($resolveController): void {
        $router->get('search/api', fn(Request $request) => $resolveController()->dashboardApi($request));
        $router->get('search/results', fn() => $resolveController()->dashboardResults());
    });

$router->get('/dashboard/service-assets/global-search/{filename}', static function (
    Request $request,
    string $filename
): Response {
    $definition = app()->get(IndependentServiceRegistry::class)->get('global-search');
    $resources = $definition?->componentPath('resources');
    if ($resources === null) {
        throw new \Core\Exceptions\Http\NotFoundHttpException();
    }

    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($extension, ['css', 'js'], true)) {
        throw new \Core\Exceptions\Http\NotFoundHttpException();
    }
    app()->get(FileInterface::class)->setPath(
        'global-search-service-assets',
        $resources . DIRECTORY_SEPARATOR . $extension
    );

    return app()->get(AssetManager::class)->respond($request, 'global-search-service-assets', $filename);
});
