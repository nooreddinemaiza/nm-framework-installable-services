<?php

declare(strict_types=1);

namespace NM\Services\SocialAuth\Social;

/** Tentative de connexion en cours (stockée en session le temps du va-et-vient). */
final class SocialFlow
{
    public function __construct(
        public readonly string $provider,
        public readonly string $state,
        public readonly string $mode,
        public readonly int|string|null $userId,
        public readonly ?string $verifier,
        public readonly ?string $nonce,
        public readonly int $expiresAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param array<string, mixed> $d */
    public static function fromArray(array $d): ?self
    {
        foreach (['provider', 'state', 'mode', 'expiresAt'] as $k) {
            if (!isset($d[$k])) {
                return null;
            }
        }
        if (!is_string($d['provider']) || !is_string($d['state']) || !is_string($d['mode']) || !is_int($d['expiresAt'])) {
            return null;
        }
        $userId = $d['userId'] ?? null;
        $verifier = $d['verifier'] ?? null;
        $nonce = $d['nonce'] ?? null;
        if (!(is_null($userId) || is_int($userId) || is_string($userId))
            || !(is_null($verifier) || is_string($verifier))
            || !(is_null($nonce) || is_string($nonce))) {
            return null;
        }

        return new self($d['provider'], $d['state'], $d['mode'], $userId, $verifier, $nonce, $d['expiresAt']);
    }
}
