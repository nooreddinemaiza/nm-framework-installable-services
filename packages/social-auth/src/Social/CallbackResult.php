<?php

declare(strict_types=1);

namespace NM\Services\SocialAuth\Social;

final class CallbackResult
{
    public const LOGGED_IN = 'logged_in';
    public const ACCOUNT_CREATED = 'account_created';
    public const LINKED = 'linked';
    public const TWO_FACTOR_PENDING = 'two_factor_pending';

    public function __construct(
        public readonly string $outcome,
        public readonly string $provider,
        public readonly int|string $userId,
        public readonly string $email = '',
    ) {
    }

    public function openedSession(): bool
    {
        return in_array($this->outcome, [self::LOGGED_IN, self::ACCOUNT_CREATED], true);
    }

    public function requiresTwoFactor(): bool
    {
        return $this->outcome === self::TWO_FACTOR_PENDING;
    }
}
