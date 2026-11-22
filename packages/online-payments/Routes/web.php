<?php

use Core\Http\Request;
use Core\Http\Response;
use Core\Middleware\AuthMiddleware;
use Core\Middleware\CsrfMiddleware;
use Core\Routing\Router;
use NM\Services\OnlinePayments\Controllers\OnlinePaymentController;
use NM\Services\OnlinePayments\OnlinePayments\OnlinePaymentService;

$resolveController = static fn(): OnlinePaymentController => new OnlinePaymentController(
    app()->get(OnlinePaymentService::class),
    app()->viewFactory(),
    app()->session(),
);
$dispatch = static function (callable $action) use ($resolveController): Response {
    if (!app()->environment()->getBool('DB_ON', false)) {
        return new Response('Le service Paiements en ligne nécessite une base de données configurée.', 503);
    }

    return $action($resolveController());
};

$router->prefix('dashboard/')
    ->middleware(AuthMiddleware::class)
    ->group(function (Router $router) use ($dispatch): void {
        $router->prefix('/online-payments')
            ->group(function (Router $router) use ($dispatch): void {
                $router->get('', fn() => $dispatch(
                    static fn(OnlinePaymentController $controller) => $controller->view()
                ));
                foreach ([
                    '/configuration' => 'saveConfiguration',
                    '/providers/save' => 'saveProvider',
                    '/reconciliation/retry' => 'retryReconciliation',
                ] as $path => $method) {
                    $router->middleware(CsrfMiddleware::class)->post(
                        $path,
                        fn(Request $request) => $dispatch(
                            static fn(OnlinePaymentController $controller) => $controller->{$method}($request)
                        )
                    );
                }
            });
    });

$router->get('/online-payment/return', fn(Request $request) => $dispatch(
    static fn(OnlinePaymentController $controller) => $controller->returnFromProvider($request)
));
$router->post('/online-payment/webhook/{providerCode}', fn(Request $request, string $providerCode) => $dispatch(
    static fn(OnlinePaymentController $controller) => $controller->webhook($request, $providerCode)
));
