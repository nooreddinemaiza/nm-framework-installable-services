<?php

declare(strict_types=1);

namespace NM\Services\SocialAuth\Social\Contracts;

use NM\Services\SocialAuth\Social\ExternalIdentity;
use Core\Security\Auth\AuthenticatableInterface;

interface SocialAccountRepositoryInterface
{
    public function findUserIdByIdentity(string $provider, string $subject): int|string|null;

    public function findUser(int|string $id): ?AuthenticatableInterface;

    public function isUserEmailVerified(int|string $id): bool;

    public function emailExists(string $email): bool;

    /** Crée le compte local ET l'association, de façon atomique. */
    public function createUserWithIdentity(ExternalIdentity $identity): AuthenticatableInterface;

    public function linkIdentity(int|string $userId, ExternalIdentity $identity): void;

    public function touchLogin(string $provider, string $subject): void;

    /** @return list<string> */
    public function providersFor(int|string $userId): array;

    public function canUnlink(int|string $userId, string $provider): bool;

    public function unlinkIdentity(int|string $userId, string $provider): bool;
}
