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
use NM\Services\Messaging\Controllers\ConversationController;
use NM\Services\Messaging\Middleware\MessagingServiceMiddleware;

$controller = static fn(): ConversationController => new ConversationController(
    app()->viewFactory(),
    app()->session()
);

$router->prefix('dashboard/')
    ->middleware(['throthle:500', AuthMiddleware::class, 'access:' . User::MODERATOR_LEVEL])
    ->group(function (Router $router) use ($controller): void {
        $router->prefix('conversations')
            ->middleware(MessagingServiceMiddleware::class)
            ->group(function (Router $router) use ($controller): void {
                $router->controller(ConversationController::class)->getGroup([
                    '/' => 'index',
                    '/inbox' => 'inbox',
                    '/list' => 'index',
                    '/users' => 'discoverUsers',
                    '/groups' => 'groups',
                    '/messages' => 'messages',
                    '/unread-count' => 'unreadCount',
                    '/media' => 'media',
                    '/default-media' => 'defaultMedia',
                    '/policies' => 'policies',
                ]);

                $router->controller(ConversationController::class)
                    ->prefix('admin')
                    ->group(function (Router $router): void {
                        $router->get('', 'adminIndex');
                        $router->postGroup([
                            '/list' => 'adminList',
                            '/bulk-action' => 'adminBulkAction',
                            'stats' => 'adminStats',
                        ]);
                        $router->prefix('messages')->group(function (Router $router): void {
                            $router->post('list', 'adminMessages');
                            $router->get('media', 'adminMessageMedia');
                            $router->deleteGroup([
                                '/remove' => 'adminRemoveMessage',
                                '/remove-selection' => 'adminRemoveMessages',
                                'remove-all' => 'adminRemoveAllMessages',
                            ]);
                        });
                    });

                $router->controller(ConversationController::class)
                    ->middleware(CsrfMiddleware::class)
                    ->postGroup([
                        '/open' => 'open',
                        '/send' => 'send',
                        '/read' => 'markRead',
                        '/read-all' => 'markAllRead',
                        '/finish' => 'finish',
                        '/preserve-media' => 'preserveMedia',
                        '/admin-action' => 'adminAction',
                        '/policies' => 'policies',
                        '/policies/default-media' => 'uploadDefaultMedia',
                    ]);
            });

        $router->prefix('account')->middleware(MessagingServiceMiddleware::class)
            ->get('/unread-messages', fn(Request $request) => $controller()->unreadCount($request));
    });

$router->get('/dashboard/service-assets/messaging/{filename}', static function (
    Request $request,
    string $filename
): Response {
    $definition = app()->get(IndependentServiceRegistry::class)->get('messaging');
    $resources = $definition?->componentPath('resources');
    if ($resources === null || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'js') {
        throw new \Core\Exceptions\Http\NotFoundHttpException();
    }

    app()->get(FileInterface::class)->setPath(
        'messaging-service-assets',
        $resources . DIRECTORY_SEPARATOR . 'scripts'
    );

    return app()->get(AssetManager::class)->respond($request, 'messaging-service-assets', $filename);
});
