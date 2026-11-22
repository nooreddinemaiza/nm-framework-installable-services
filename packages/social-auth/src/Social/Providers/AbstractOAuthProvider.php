<?php

declare(strict_types=1);

namespace NM\Services\SocialAuth\Social\Providers;

use NM\Services\SocialAuth\Social\Contracts\HttpClientInterface;
use NM\Services\SocialAuth\Social\Contracts\SocialProviderInterface;
use NM\Services\SocialAuth\Social\Exceptions\ProviderException;
use NM\Services\SocialAuth\Social\ProviderConfig;
use NM\Services\SocialAuth\Social\TokenSet;

/** Mécanique OAuth 2.0 « code d'autorisation » commune ; chaque adaptateur ne définit que ses particularités. */
abstract class AbstractOAuthProvider implements SocialProviderInterface
{
    /** Identifiant stable, utilisé comme partie de la clé d'identité en base. */
    public const ID = '';

    public function __construct(
        protected readonly ProviderConfig $config,
        protected readonly HttpClientInterface $http,
    ) {
    }

    abstract protected function authorizeEndpoint(): string;

    abstract protected function tokenEndpoint(): string;

    /** @return list<string> */
    abstract protected function defaultScopes(): array;

    /** @return array<string, string> */
    protected function extraAuthorizeParams(): array
    {
        return [];
    }

    public function id(): string
    {
        return static::ID;
    }

    public function label(): string
    {
        return $this->config->label;
    }

    public function usesPkce(): bool
    {
        return $this->config->pkce;
    }

    public function usesNonce(): bool
    {
        return false;
    }

    public function authorizationUrl(string $state, ?string $nonce, ?string $codeChallenge): string
    {
        $params = [
            'response_type' => 'code', // jamais de flux implicite
            'client_id' => $this->config->clientId,
            'redirect_uri' => $this->config->redirectUri,
            'scope' => implode(' ', $this->config->scopes ?: $this->defaultScopes()),
            'state' => $state,
        ] + $this->extraAuthorizeParams();

        if ($this->usesPkce() && $codeChallenge !== null) {
            $params['code_challenge'] = $codeChallenge;
            $params['code_challenge_method'] = 'S256';
        }
        if ($this->usesNonce() && $nonce !== null) {
            $params['nonce'] = $nonce;
        }

        return $this->authorizeEndpoint() . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code, ?string $codeVerifier): TokenSet
    {
        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->config->redirectUri,
            'client_id' => $this->config->clientId,
            'client_secret' => $this->config->clientSecret,
        ];
        if ($this->usesPkce() && $codeVerifier !== null) {
            $form['code_verifier'] = $codeVerifier;
        }

        $data = $this->decodeJson(
            $this->http->request('POST', $this->tokenEndpoint(), ['Accept' => 'application/json'], $form),
            'échange du code'
        );
        $access = $data['access_token'] ?? null;
        if (!is_string($access) || $access === '') {
            throw new ProviderException('échange du code : jeton absent');
        }
        $idToken = isset($data['id_token']) && is_string($data['id_token']) ? $data['id_token'] : null;

        return new TokenSet($access, $idToken);
    }

    /**
     * @param array{status: int, body: string} $response
     * @return array<string|int, mixed>
     * @throws ProviderException messages SANS corps de réponse (il peut contenir des jetons)
     */
    protected function decodeJson(array $response, string $step): array
    {
        if ($response['status'] !== 200) {
            throw new ProviderException(sprintf('%s : HTTP %d', $step, $response['status']));
        }
        $data = json_decode($response['body'], true, 32);
        if (!is_array($data)) {
            throw new ProviderException($step . ' : réponse JSON invalide');
        }

        return $data;
    }

    /** @return array<string, string> */
    protected function bearer(TokenSet $tokens): array
    {
        return ['Authorization' => 'Bearer ' . $tokens->accessToken, 'Accept' => 'application/json'];
    }
}
