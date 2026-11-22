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
use NM\Services\PageManagement\Controllers\PageManagementController;
use NM\Services\PageManagement\Pages\PageManagementService;
use NM\Services\PageManagement\Services\PageManagementState;

$resolveController = static fn(): PageManagementController => new PageManagementController(
    app()->get(PageManagementService::class),
    app()->viewFactory(),
    app()->session(),
    app()->get(PageManagementState::class)
);
$dispatch = static function (callable $action) use ($resolveController): Response {
    if (!app()->environment()->getBool('DB_ON', false)) {
        return new Response('Le service Gestion des pages nécessite une base de données configurée.', 503);
    }

    return $action($resolveController());
};
$csrfPost = static fn(Router $router, string $path, callable $action) => $router
    ->middleware(CsrfMiddleware::class)->post($path, $action);

$router->prefix('dashboard/')
    ->middleware(['throthle:500', AuthMiddleware::class, 'access:' . User::MODERATOR_LEVEL])
    ->group(function (Router $router) use ($dispatch, $csrfPost): void {
        $router->prefix('pages')->group(function (Router $router) use ($dispatch, $csrfPost): void {
            $router->get('', fn() => $dispatch(static fn(PageManagementController $controller) => $controller->index()));
            $router->get('/data', fn(Request $request) => $dispatch(static fn(PageManagementController $controller) => $controller->data($request)));
            $router->get('/templates', fn() => $dispatch(static fn(PageManagementController $controller) => $controller->templates()));
            $router->get('/sections', fn() => $dispatch(static fn(PageManagementController $controller) => $controller->sections()));
            $router->get('/menus', fn() => $dispatch(static fn(PageManagementController $controller) => $controller->menus()));
            $router->get('/{id}/data', fn(Request $request, string $id) => $dispatch(static fn(PageManagementController $controller) => $controller->pageData((int) $id)));
            $router->get('/{id}/preview', fn(Request $request, string $id) => $dispatch(static fn(PageManagementController $controller) => $controller->preview((int) $id)));

            $csrfPost($router, '/preview', fn(Request $request) => $dispatch(static fn(PageManagementController $controller) => $controller->previewWorking($request)));
            $csrfPost($router, '/create', fn(Request $request) => $dispatch(static fn(PageManagementController $controller) => $controller->store($request)));
            $csrfPost($router, '/create-from-template', fn(Request $request) => $dispatch(static fn(PageManagementController $controller) => $controller->createFromTemplate($request)));
            $csrfPost($router, '/{id}/update', fn(Request $request, string $id) => $dispatch(static fn(PageManagementController $controller) => $controller->update($request, (int) $id)));
            $csrfPost($router, '/{id}/publish', fn(Request $request, string $id) => $dispatch(static fn(PageManagementController $controller) => $controller->publish((int) $id)));
            $csrfPost($router, '/{id}/unpublish', fn(Request $request, string $id) => $dispatch(static fn(PageManagementController $controller) => $controller->unpublish((int) $id)));
            $csrfPost($router, '/{id}/archive', fn(Request $request, string $id) => $dispatch(static fn(PageManagementController $controller) => $controller->archive((int) $id)));
            $csrfPost($router, '/{id}/homepage', fn(Request $request, string $id) => $dispatch(static fn(PageManagementController $controller) => $controller->designateHomepage((int) $id)));
            $csrfPost($router, '/{id}/duplicate', fn(Request $request, string $id) => $dispatch(static fn(PageManagementController $controller) => $controller->duplicate((int) $id)));
            $csrfPost($router, '/{id}/delete', fn(Request $request, string $id) => $dispatch(static fn(PageManagementController $controller) => $controller->delete((int) $id)));
            $csrfPost($router, '/{pageId}/templates', fn(Request $request, string $pageId) => $dispatch(static fn(PageManagementController $controller) => $controller->createTemplate($request, (int) $pageId)));
            $csrfPost($router, '/templates/{id}/update', fn(Request $request, string $id) => $dispatch(static fn(PageManagementController $controller) => $controller->updateTemplate($request, (int) $id)));
            $csrfPost($router, '/templates/{id}/duplicate', fn(Request $request, string $id) => $dispatch(static fn(PageManagementController $controller) => $controller->duplicateTemplate($request, (int) $id)));
            $csrfPost($router, '/templates/{id}/delete', fn(Request $request, string $id) => $dispatch(static fn(PageManagementController $controller) => $controller->deleteTemplate((int) $id)));
            $csrfPost($router, '/sections', fn(Request $request) => $dispatch(static fn(PageManagementController $controller) => $controller->createSection($request)));
            $csrfPost($router, '/sections/{id}/update', fn(Request $request, string $id) => $dispatch(static fn(PageManagementController $controller) => $controller->updateSection($request, (int) $id)));
            $csrfPost($router, '/sections/{id}/delete', fn(Request $request, string $id) => $dispatch(static fn(PageManagementController $controller) => $controller->deleteSection((int) $id)));
            $csrfPost($router, '/{pageId}/sections/library', fn(Request $request, string $pageId) => $dispatch(static fn(PageManagementController $controller) => $controller->addLibrarySection($request, (int) $pageId)));
            $csrfPost($router, '/{pageId}/sections/{usageId}/remove', fn(Request $request, string $pageId, string $usageId) => $dispatch(static fn(PageManagementController $controller) => $controller->removeSection((int) $pageId, (int) $usageId)));
            $csrfPost($router, '/menus', fn(Request $request) => $dispatch(static fn(PageManagementController $controller) => $controller->saveMenu($request)));
            $csrfPost($router, '/menus/{menuId}/update', fn(Request $request, string $menuId) => $dispatch(static fn(PageManagementController $controller) => $controller->saveMenu($request, (int) $menuId)));
            $csrfPost($router, '/menus/{menuId}/delete', fn(Request $request, string $menuId) => $dispatch(static fn(PageManagementController $controller) => $controller->deleteMenu((int) $menuId)));
            $csrfPost($router, '/menus/{menuId}/items', fn(Request $request, string $menuId) => $dispatch(static fn(PageManagementController $controller) => $controller->saveMenuItem($request, (int) $menuId)));
            $csrfPost($router, '/menus/{menuId}/items/{itemId}/update', fn(Request $request, string $menuId, string $itemId) => $dispatch(static fn(PageManagementController $controller) => $controller->saveMenuItem($request, (int) $menuId, (int) $itemId)));
            $csrfPost($router, '/menus/{menuId}/items/{itemId}/delete', fn(Request $request, string $menuId, string $itemId) => $dispatch(static fn(PageManagementController $controller) => $controller->deleteMenuItem((int) $menuId, (int) $itemId)));
        });
    });

$router->get('/p/{slug}', fn(Request $request, string $slug) => $dispatch(
    static fn(PageManagementController $controller) => $controller->publicPage($slug)
));

$router->get('/dashboard/service-assets/page-management/{filename}', static function (
    Request $request,
    string $filename
): Response {
    $definition = app()->get(IndependentServiceRegistry::class)->get('page-management');
    $resources = $definition?->componentPath('resources');
    if ($resources === null) {
        throw new \Core\Exceptions\Http\NotFoundHttpException();
    }

    app()->get(FileInterface::class)->setPath(
        'page-management-service-assets',
        $resources . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'scripts'
    );

    return app()->get(AssetManager::class)->respond($request, 'page-management-service-assets', $filename);
});
