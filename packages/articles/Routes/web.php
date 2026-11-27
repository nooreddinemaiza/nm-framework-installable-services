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
use NM\Services\Articles\Controllers\ArticleController;
use NM\Services\Articles\Services\ArticleConfigurationStore;
use NM\Services\Articles\Services\ArticleService;
use NM\Services\Articles\Services\ArticleServiceState;

$resolveController = static fn(): ArticleController => new ArticleController(
    app()->get(ArticleService::class),
    app()->get(ArticleConfigurationStore::class),
    app()->viewFactory(),
    app()->session(),
    app()->get(ArticleServiceState::class)
);
$dispatch = static function (callable $action) use ($resolveController): Response {
    if (!app()->environment()->getBool('DB_ON', false)) {
        return new Response('Le service Articles nécessite une base de données configurée.', 503);
    }

    return $action($resolveController());
};

$router->prefix('dashboard/')
    ->middleware(['throthle:500', AuthMiddleware::class, 'access:' . User::MODERATOR_LEVEL])
    ->group(function (Router $router) use ($dispatch): void {
        $router->prefix('articles')
            ->withoutMiddleware('access:' . User::MODERATOR_LEVEL)
            ->group(function (Router $router) use ($dispatch): void {
                $router->get('', fn(Request $request) => $dispatch(
                    static fn(ArticleController $controller) => $controller->index($request)
                ));
                $router->get('/statistics', fn() => $dispatch(
                    static fn(ArticleController $controller) => $controller->statistics()
                ));
                $router->get('/data', fn(Request $request) => $dispatch(
                    static fn(ArticleController $controller) => $controller->data($request)
                ));
                $router->middleware(CsrfMiddleware::class)->post('/configuration', fn(Request $request) => $dispatch(
                    static fn(ArticleController $controller) => $controller->saveConfiguration($request)
                ));
                $router->get('/{id}/data', fn(Request $request, string $id) => $dispatch(
                    static fn(ArticleController $controller) => $controller->articleData((int) $id)
                ));
                $router->get('/preview/{id}', fn(Request $request, string $id) => $dispatch(
                    static fn(ArticleController $controller) => $controller->preview((int) $id)
                ));
                $router->middleware(CsrfMiddleware::class)->post('/create', fn(Request $request) => $dispatch(
                    static fn(ArticleController $controller) => $controller->store($request)
                ));
                $router->middleware(CsrfMiddleware::class)->post('/{id}/update', fn(Request $request, string $id) => $dispatch(
                    static fn(ArticleController $controller) => $controller->update($request, (int) $id)
                ));
                $router->middleware(CsrfMiddleware::class)->post('/{id}/publish', fn(Request $request, string $id) => $dispatch(
                    static fn(ArticleController $controller) => $controller->publish($request, (int) $id)
                ));
                $router->middleware(CsrfMiddleware::class)->post('/{id}/unpublish', fn(Request $request, string $id) => $dispatch(
                    static fn(ArticleController $controller) => $controller->unpublish($request, (int) $id)
                ));
                $router->middleware(CsrfMiddleware::class)->post('/{id}/archive', fn(Request $request, string $id) => $dispatch(
                    static fn(ArticleController $controller) => $controller->archive($request, (int) $id)
                ));
                $router->middleware(CsrfMiddleware::class)->post('/{id}/delete', fn(Request $request, string $id) => $dispatch(
                    static fn(ArticleController $controller) => $controller->delete($request, (int) $id)
                ));
                $router->middleware(CsrfMiddleware::class)->post('/categories', fn(Request $request) => $dispatch(
                    static fn(ArticleController $controller) => $controller->createCategory($request)
                ));
                $router->middleware(CsrfMiddleware::class)->post('/categories/{id}/delete', fn(Request $request, string $id) => $dispatch(
                    static fn(ArticleController $controller) => $controller->deleteCategory($request, (int) $id)
                ));
            });
    });

$router->get('/articles', fn(Request $request) => $dispatch(
    static fn(ArticleController $controller) => $controller->publicIndex($request)
));
$router->get('/articles/{slug}', fn(Request $request, string $slug) => $dispatch(
    static fn(ArticleController $controller) => $controller->publicDetail($request, $slug)
));

$router->get('/dashboard/service-assets/articles/{filename}', static function (
    Request $request,
    string $filename
): Response {
    $definition = app()->get(IndependentServiceRegistry::class)->get('articles');
    $resources = $definition?->componentPath('resources');
    if ($resources === null) {
        throw new \Core\Exceptions\Http\NotFoundHttpException();
    }

    app()->get(FileInterface::class)->setPath(
        'articles-service-assets',
        $resources . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'scripts'
    );

    return app()->get(AssetManager::class)->respond($request, 'articles-service-assets', $filename);
});
