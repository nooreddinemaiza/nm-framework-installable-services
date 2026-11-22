<?php

declare(strict_types=1);

namespace NM\Services\SocialAuth\Middleware;

use Closure;
use Core\Exceptions\Http\ServiceNotInstalledException;
use Core\Http\Request;
use Core\Http\Response;
use Core\Middleware\MiddlewareInterface;
use NM\Services\SocialAuth\Social\SocialAuthServiceState;

final class SocialAuthServiceMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!app()->environment()->getBool('DB_ON', false)) {
            throw new ServiceNotInstalledException('Le service d’authentification sociale n’est pas installé ou activé.');
        }

        app()->get(SocialAuthServiceState::class)->assertEnabled();

        return $next($request);
    }
}
