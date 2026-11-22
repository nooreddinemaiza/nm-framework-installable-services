<?php

declare(strict_types=1);

namespace NM\Services\SocialAuth\Social\Providers;

use NM\Services\SocialAuth\Social\ExternalIdentity;
use NM\Services\SocialAuth\Social\Exceptions\ProviderException;
use NM\Services\SocialAuth\Social\TokenSet;

/**
 * Facebook : l'e-mail n'est pas garanti vérifié → toujours « non vérifié »
 * (pas de création de compte avec Facebook). PKCE désactivé par défaut.
 * Option `graph_version` (ex. "v21.0") : à tenir à jour selon la politique de Meta.
 */
final class FacebookProvider extends AbstractOAuthProvider
{
    public const ID = 'facebook';

    private function version(): string
    {
        $v = (string) ($this->config->options['graph_version'] ?? 'v21.0');

        return preg_match('/^v\d{1,2}\.\d$/', $v) === 1 ? $v : 'v21.0';
    }

    protected function authorizeEndpoint(): string
    {
        return 'https://www.facebook.com/' . $this->version() . '/dialog/oauth';
    }

    protected function tokenEndpoint(): string
    {
        return 'https://graph.facebook.com/' . $this->version() . '/oauth/access_token';
    }

    protected function defaultScopes(): array
    {
        return ['public_profile', 'email'];
    }

    public function fetchIdentity(TokenSet $tokens, ?string $expectedNonce): ExternalIdentity
    {
        $proof = hash_hmac('sha256', $tokens->accessToken, $this->config->clientSecret);
        $url = 'https://graph.facebook.com/' . $this->version() . '/me?' . http_build_query([
            'fields' => 'id,name,email',
            'appsecret_proof' => $proof,
        ], '', '&', PHP_QUERY_RFC3986);

        $me = $this->decodeJson($this->http->request('GET', $url, $this->bearer($tokens)), 'profil Facebook');
        $id = $me['id'] ?? null;
        if (!is_string($id) || !ctype_digit($id)) {
            throw new ProviderException('profil Facebook : id invalide');
        }

        return new ExternalIdentity(
            self::ID,
            $id,
            is_string($me['email'] ?? null) ? $me['email'] : null,
            false,
            is_string($me['name'] ?? null) ? $me['name'] : null,
        );
    }
}
