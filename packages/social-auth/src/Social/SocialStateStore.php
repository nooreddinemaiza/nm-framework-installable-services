<?php

declare(strict_types=1);

namespace NM\Services\SocialAuth\Social;

use NM\Services\SocialAuth\Social\Contracts\FlowStorageInterface;
use NM\Services\SocialAuth\Social\Exceptions\SocialAuthException;

/**
 * Génère, stocke (session), vérifie et CONSOMME le `state` : imprévisible
 * (256 bits), lié au fournisseur et à la tentative, limité dans le temps,
 * à usage unique (la lecture supprime l'entrée, qu'elle réussisse ou non).
 */
final class SocialStateStore
{
    public const MODE_LOGIN = 'login';
    public const MODE_LINK = 'link';

    private const STATE_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    private readonly \Closure $clock;

    public function __construct(
        private readonly FlowStorageInterface $storage,
        private readonly int $ttl = 600,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function create(string $provider, string $mode, int|string|null $userId, bool $pkce, bool $nonce): SocialFlow
    {
        $this->storage->purge();

        $flow = new SocialFlow(
            provider: $provider,
            state: self::token(),
            mode: $mode,
            userId: $userId,
            verifier: $pkce ? self::token() : null,
            nonce: $nonce ? self::token() : null,
            expiresAt: ($this->clock)() + $this->ttl,
        );
        $this->storage->put(self::key($flow->state), $flow->toArray(), $this->ttl);

        return $flow;
    }

    /** @throws SocialAuthException STATE_INVALID si absent, expiré, déjà utilisé ou incorrect. */
    public function consume(string $provider, mixed $state): SocialFlow
    {
        if (!is_string($state) || preg_match(self::STATE_PATTERN, $state) !== 1) {
            throw new SocialAuthException(SocialAuthException::STATE_INVALID);
        }

        $raw = $this->storage->pull(self::key($state)); // usage unique
        $flow = $raw === null ? null : SocialFlow::fromArray($raw);

        if ($flow === null
            || !hash_equals($flow->state, $state)
            || !hash_equals($flow->provider, $provider)
            || $flow->expiresAt < ($this->clock)()
        ) {
            throw new SocialAuthException(SocialAuthException::STATE_INVALID);
        }

        return $flow;
    }

    /** Challenge PKCE (méthode S256). */
    public static function challenge(string $verifier): string
    {
        return self::b64url(hash('sha256', $verifier, true));
    }

    private static function token(): string
    {
        return self::b64url(random_bytes(32)); // 43 caractères
    }

    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function key(string $state): string
    {
        return 'social_flow_' . hash('sha256', $state);
    }
}
