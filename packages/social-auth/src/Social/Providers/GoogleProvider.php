<?php

declare(strict_types=1);

namespace NM\Services\SocialAuth\Social\Providers;

use NM\Services\SocialAuth\Social\ExternalIdentity;
use NM\Services\SocialAuth\Social\Exceptions\ProviderException;
use NM\Services\SocialAuth\Social\TokenSet;

final class GoogleProvider extends AbstractOAuthProvider
{
    public const ID = 'google';

    protected function authorizeEndpoint(): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth';
    }

    protected function tokenEndpoint(): string
    {
        return 'https://oauth2.googleapis.com/token';
    }

    protected function defaultScopes(): array
    {
        return ['openid', 'email', 'profile'];
    }

    public function usesNonce(): bool
    {
        return true;
    }

    public function fetchIdentity(TokenSet $tokens, ?string $expectedNonce): ExternalIdentity
    {
        if ($tokens->idToken === null) {
            throw new ProviderException('id_token absent');
        }
        $claims = IdTokenValidator::validate(
            $tokens->idToken,
            $this->config->clientId,
            static fn(string $iss): bool => in_array($iss, ['https://accounts.google.com', 'accounts.google.com'], true),
            $expectedNonce,
        );

        return new ExternalIdentity(
            self::ID,
            (string) $claims['sub'],
            is_string($claims['email'] ?? null) ? $claims['email'] : null,
            filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
            is_string($claims['name'] ?? null) ? $claims['name'] : null,
        );
    }
}
