<?php

declare(strict_types=1);

namespace NM\Services\SocialAuth\Social\Providers;

use NM\Services\SocialAuth\Social\ExternalIdentity;
use NM\Services\SocialAuth\Social\Exceptions\ProviderException;
use NM\Services\SocialAuth\Social\TokenSet;

/** GitHub : OAuth 2.0 simple (pas OIDC). Identité = id numérique ; e-mail = adresse principale ET vérifiée. */
final class GitHubProvider extends AbstractOAuthProvider
{
    public const ID = 'github';

    protected function authorizeEndpoint(): string
    {
        return 'https://github.com/login/oauth/authorize';
    }

    protected function tokenEndpoint(): string
    {
        return 'https://github.com/login/oauth/access_token';
    }

    protected function defaultScopes(): array
    {
        return ['read:user', 'user:email'];
    }

    protected function extraAuthorizeParams(): array
    {
        return ['allow_signup' => 'false'];
    }

    public function fetchIdentity(TokenSet $tokens, ?string $expectedNonce): ExternalIdentity
    {
        $headers = $this->bearer($tokens) + ['X-GitHub-Api-Version' => '2022-11-28'];
        $user = $this->decodeJson($this->http->request('GET', 'https://api.github.com/user', $headers), 'profil GitHub');
        $id = $user['id'] ?? null;
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            throw new ProviderException('profil GitHub : id invalide');
        }

        $email = null;
        $emails = $this->decodeJson($this->http->request('GET', 'https://api.github.com/user/emails', $headers), 'e-mails GitHub');
        foreach ($emails as $row) {
            if (is_array($row) && ($row['primary'] ?? false) === true && ($row['verified'] ?? false) === true
                && is_string($row['email'] ?? null)) {
                $email = $row['email'];
                break;
            }
        }

        $name = is_string($user['name'] ?? null) && $user['name'] !== '' ? $user['name'] : ($user['login'] ?? null);

        return new ExternalIdentity(self::ID, (string) $id, $email, $email !== null, is_string($name) ? $name : null);
    }
}
