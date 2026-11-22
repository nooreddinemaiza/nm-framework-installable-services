<?php

declare(strict_types=1);

namespace NM\Services\SocialAuth\Social\Providers;

use NM\Services\SocialAuth\Social\ExternalIdentity;
use NM\Services\SocialAuth\Social\Exceptions\ProviderException;
use NM\Services\SocialAuth\Social\TokenSet;

/**
 * Option `tenant` : "common" (défaut), "organizations", "consumers" ou un GUID de tenant.
 * L'e-mail Microsoft n'est PAS garanti vérifié : il est toujours traité comme non vérifié,
 * donc inutilisable pour créer un compte.
 */
final class MicrosoftProvider extends AbstractOAuthProvider
{
    public const ID = 'microsoft';
    private const GENERIC_TENANTS = ['common', 'organizations', 'consumers'];

    private function tenant(): string
    {
        $t = (string) ($this->config->options['tenant'] ?? 'common');

        return preg_match('/^(common|organizations|consumers|[0-9a-f-]{36})$/i', $t) === 1 ? strtolower($t) : 'common';
    }

    protected function authorizeEndpoint(): string
    {
        return 'https://login.microsoftonline.com/' . $this->tenant() . '/oauth2/v2.0/authorize';
    }

    protected function tokenEndpoint(): string
    {
        return 'https://login.microsoftonline.com/' . $this->tenant() . '/oauth2/v2.0/token';
    }

    protected function defaultScopes(): array
    {
        return ['openid', 'profile', 'email'];
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
        $tenant = $this->tenant();
        $claims = IdTokenValidator::validate(
            $tokens->idToken,
            $this->config->clientId,
            static function (string $iss, array $claims) use ($tenant): bool {
                $tid = $claims['tid'] ?? null;
                if (!is_string($tid) || preg_match('/^[0-9a-f-]{36}$/i', $tid) !== 1) {
                    return false;
                }
                if (!in_array($tenant, self::GENERIC_TENANTS, true) && strtolower($tid) !== $tenant) {
                    return false;
                }

                return $iss === 'https://login.microsoftonline.com/' . $tid . '/v2.0';
            },
            $expectedNonce,
        );

        return new ExternalIdentity(
            self::ID,
            (string) $claims['sub'],
            is_string($claims['email'] ?? null) ? $claims['email'] : null,
            false,
            is_string($claims['name'] ?? null) ? $claims['name'] : null,
        );
    }
}
