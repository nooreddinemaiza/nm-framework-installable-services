<?php

declare(strict_types=1);

namespace NM\Services\SocialAuth\Social;

final class ProviderConfig
{
    /**
     * @param list<string> $scopes Vide = scopes par défaut de l'adaptateur.
     * @param array<string, mixed> $options Options propres à l'adaptateur (ex. tenant Microsoft).
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly string $redirectUri,
        public readonly array $scopes = [],
        public readonly bool $pkce = true,
        public readonly array $options = [],
    ) {
    }

    public function isComplete(): bool
    {
        return $this->clientId !== ''
            && $this->clientSecret !== ''
            && preg_match('#^https?://[^\s]+$#i', $this->redirectUri) === 1;
    }

    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'clientSecret' => '*** masqué ***'];
    }
}
