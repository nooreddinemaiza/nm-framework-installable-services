<?php

declare(strict_types=1);

namespace NM\Services\SocialAuth\Social\Contracts;

use Core\Security\Auth\AuthenticatableInterface;

interface LocalSessionInterface
{
    /** Ouvre la session locale via le mécanisme existant. */
    public function login(AuthenticatableInterface $user): void;
}
