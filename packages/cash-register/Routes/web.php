<?php

use App\Models\User;
use Core\Http\Request;
use Core\Middleware\AuthMiddleware;
use Core\Middleware\CsrfMiddleware;
use Core\Routing\Router;
use NM\Services\CashRegister\Controllers\CashRegisterController;
use NM\Services\CashRegister\Cash\CashRegisterService;

$resolveController = static fn(): CashRegisterController => new CashRegisterController(
    app()->get(CashRegisterService::class),
    app()->viewFactory(),
    app()->session()
);
$dispatch = static function (callable $action) use ($resolveController): \Core\Http\Response {
    if (!app()->environment()->getBool('DB_ON', false)) {
        return new \Core\Http\Response('Le service Caisse nécessite une base de données configurée.', 503);
    }

    return $action($resolveController());
};

$router->prefix('dashboard/')
    ->middleware(['throthle:500', AuthMiddleware::class, 'access:' . User::MODERATOR_LEVEL])
    ->group(function (Router $router) use ($dispatch): void {
        $router->prefix('cash')
            ->withoutMiddleware('access:' . User::MODERATOR_LEVEL)
            ->group(function (Router $router) use ($dispatch): void {
                $router->get('', fn(Request $request) => $dispatch(
                    static fn(CashRegisterController $controller) => $controller->view($request)
                ));
                $router->get('/receipt', fn(Request $request) => $dispatch(
                    static fn(CashRegisterController $controller) => $controller->receipt($request)
                ));
                $router->middleware(CsrfMiddleware::class)->post('/configuration', fn(Request $request) => $dispatch(
                    static fn(CashRegisterController $controller) => $controller->saveConfiguration($request)
                ));
                $router->middleware(CsrfMiddleware::class)->post('/registers/save', fn(Request $request) => $dispatch(
                    static fn(CashRegisterController $controller) => $controller->saveRegister($request)
                ));
                $router->middleware(CsrfMiddleware::class)->post('/registers/status', fn(Request $request) => $dispatch(
                    static fn(CashRegisterController $controller) => $controller->setRegisterStatus($request)
                ));
                $router->middleware(CsrfMiddleware::class)->post('/sessions/open', fn(Request $request) => $dispatch(
                    static fn(CashRegisterController $controller) => $controller->openSession($request)
                ));
                $router->middleware(CsrfMiddleware::class)->post('/sessions/close', fn(Request $request) => $dispatch(
                    static fn(CashRegisterController $controller) => $controller->closeSession($request)
                ));
                $router->middleware(CsrfMiddleware::class)->post('/movements', fn(Request $request) => $dispatch(
                    static fn(CashRegisterController $controller) => $controller->recordMovement($request)
                ));
                $router->middleware(CsrfMiddleware::class)->post('/payments', fn(Request $request) => $dispatch(
                    static fn(CashRegisterController $controller) => $controller->recordSalePayment($request)
                ));
                $router->middleware(CsrfMiddleware::class)->post('/refunds', fn(Request $request) => $dispatch(
                    static fn(CashRegisterController $controller) => $controller->refundSalePayment($request)
                ));
            });
    });
