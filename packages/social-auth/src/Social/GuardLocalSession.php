<?php

declare(strict_types=1);

namespace NM\Services\SocialAuth\Social;

use NM\Services\SocialAuth\Social\Contracts\LocalSessionInterface;
use Core\Security\Auth\AuthenticatableInterface;
use Core\Security\Auth\AuthManager;

/** Ouvre la session via le guard "session" existant (aucun second système de session). */
final class GuardLocalSession implements LocalSessionInterface
{
    public function __construct(private readonly AuthManager $auth)
    {
    }

    public function login(AuthenticatableInterface $user): void
    {
        $this->auth->guard('session')->login($user, false);
    }
}
