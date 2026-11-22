<?php

declare(strict_types=1);

namespace NM\Services\SocialAuth\Social;

/**
 * Jetons obtenus à l'échange du code. Gardés en mémoire le temps de la
 * requête : jamais persistés, jamais journalisés (voir __debugInfo).
 */
final class TokenSet
{
    public function __construct(
        public readonly string $accessToken,
        public readonly ?string $idToken = null,
    ) {
    }

    public function __debugInfo(): array
    {
        return ['accessToken' => '*** masqué ***', 'idToken' => $this->idToken === null ? null : '*** masqué ***'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('TokenSet ne doit pas être sérialisé.');
    }
}
