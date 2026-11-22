<?php

declare(strict_types=1);

namespace NM\Services\SocialAuth\Social;

use App\Services\Authentication;
use Core\Exceptions\Http\ServiceNotInstalledException;
use Core\Security\Auth\AuthManager;
use Core\Security\Auth\UserProviderInterface;
use Core\View\ViewFactory;

/** Façade du service social avec vérification de son état installable. */
final class SocialAuth
{
    public static function service(): SocialAuthService
    {
        if (!self::isInstalledAndEnabled()) {
            throw new ServiceNotInstalledException('Le service d’authentification sociale n’est pas installé ou activé.');
        }

        return app()->get(SocialAuthService::class);
    }

    public static function controller(): SocialAuthController
    {
        $service = self::service();
        $authentication = new Authentication(
            app()->get(ViewFactory::class),
            app()->get(AuthManager::class),
            app()->get(UserProviderInterface::class),
            app()->session(),
        );

        return new SocialAuthController(
            $service,
            app()->get(AuthManager::class),
            app()->session(),
            $authentication,
        );
    }

    /**
     * Fournisseurs à proposer sur la page de connexion (activés ET configurés).
     * Retourne [] sans erreur si la base ou la configuration est indisponible.
     *
     * @return array<string, string> id => libellé
     */
    public static function enabledProviders(): array
    {
        try {
            return self::service()->enabledProviders();
        } catch (\Throwable) {
            return [];
        }
    }

    private static function isInstalledAndEnabled(): bool
    {
        return (bool) env('DB_ON')
            && app()->get(SocialAuthServiceState::class)->isEnabled();
    }
}
