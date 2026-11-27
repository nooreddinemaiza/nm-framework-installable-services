<?php

use Core\Http\Request;
use Core\Database\ConnectionManager;
use Core\Middleware\AuthMiddleware;
use Core\Middleware\CsrfMiddleware;
use Core\Middleware\MaintenanceModeMiddleware;
use Core\Routing\Router;
use Core\Services\Registry\IndependentServiceManager;
use NM\Services\SocialAuth\Social\SocialProviderConfigurationController;
use NM\Services\SocialAuth\Middleware\SocialAuthServiceMiddleware;
use NM\Services\SocialAuth\Social\SocialAuth;

$router->middleware([AuthMiddleware::class, CsrfMiddleware::class])
    ->post('/dashboard/social-auth/configuration', static fn(Request $request) => (
        new SocialProviderConfigurationController(
            app()->session(),
            app()->get(ConnectionManager::class)->connection(),
            app()->get(IndependentServiceManager::class),
        )
    )->save($request));

$router->prefix('/connexion/social')
    ->withoutMiddleware([MaintenanceModeMiddleware::class])
    ->middleware('throthle:60')
    ->middleware(SocialAuthServiceMiddleware::class)
    ->group(function (Router $router): void {
        $router->get('/{provider}', fn(Request $request, string $provider) => SocialAuth::controller()->start($request, $provider));
        $router->get('/{provider}/callback', fn(Request $request, string $provider) => SocialAuth::controller()->callback($request, $provider));
    });

$router->prefix('/dashboard/account/securite/social')
    ->middleware([AuthMiddleware::class, CsrfMiddleware::class, SocialAuthServiceMiddleware::class])
    ->group(function (Router $router): void {
        $router->post('/{provider}/link', fn(Request $request, string $provider) => SocialAuth::controller()->link($request, $provider));
        $router->post('/{provider}/unlink', fn(Request $request, string $provider) => SocialAuth::controller()->unlink($request, $provider));
    });
