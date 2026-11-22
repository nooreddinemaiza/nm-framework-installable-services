<?php

declare(strict_types=1);

namespace NM\Services\Messaging\Middleware;

use Closure;
use Core\Exceptions\Http\ServiceNotInstalledException;
use Core\Http\Request;
use Core\Http\Response;
use Core\Middleware\MiddlewareInterface;
use NM\Services\Messaging\Services\MessagingServiceState;

final class MessagingServiceMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!app()->environment()->getBool('DB_ON', false)) {
            throw new ServiceNotInstalledException("Le service de messagerie n'est pas installé ou activé.");
        }

        app()->get(MessagingServiceState::class)->assertEnabled();

        return $next($request);
    }
}
