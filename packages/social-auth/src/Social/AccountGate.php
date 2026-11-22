<?php

declare(strict_types=1);

namespace NM\Services\SocialAuth\Social;

use NM\Services\SocialAuth\Social\Exceptions\SocialAuthException;
use Core\Security\Auth\AuthenticatableInterface;

/**
 * Mêmes contrôles d'accès et d'état que la connexion classique
 * (verrouillage, permission account.login, compte actif), sans la modifier.
 */
final class AccountGate
{
    /**
     * @param bool $skipTwoFactor false (défaut) : un compte avec 2FA local doit
     *             valider le second facteur après l'authentification sociale.
     */
    public function __construct(private readonly bool $skipTwoFactor = false)
    {
    }

    /** @throws SocialAuthException */
    public function assertCanLogin(AuthenticatableInterface $user): void
    {
        if ($user->isLocked()) {
            throw new SocialAuthException(SocialAuthException::ACCOUNT_LOCKED);
        }
        if (!$user->hasPermission('account.login')) {
            throw new SocialAuthException(SocialAuthException::ACCOUNT_BLOCKED);
        }
        if (!$user->is_active()) {
            throw new SocialAuthException(SocialAuthException::ACCOUNT_INACTIVE);
        }
    }

    public function requiresTwoFactor(AuthenticatableInterface $user): bool
    {
        return $user->isTwoFactorEnabled() && !$this->skipTwoFactor;
    }
}
