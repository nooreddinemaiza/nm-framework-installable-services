<?php

declare(strict_types=1);

namespace NM\Services\SocialAuth\Social;

use NM\Services\SocialAuth\Social\Contracts\LocalSessionInterface;
use NM\Services\SocialAuth\Social\Contracts\SocialAccountRepositoryInterface;
use NM\Services\SocialAuth\Social\Exceptions\ProviderException;
use NM\Services\SocialAuth\Social\Exceptions\SocialAuthException;

/**
 * Orchestre le flux. Ne connaît QUE le contrat SocialProviderInterface :
 * aucun code spécifique à un fournisseur ici.
 */
final class SocialAuthService
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly SocialStateStore $states,
        private readonly SocialAccountRepositoryInterface $accounts,
        private readonly AccountGate $gate,
        private readonly LocalSessionInterface $session,
        private readonly SocialAccountPolicy $policy = SocialAccountPolicy::DENY,
    ) {
    }

    /** @return array<string, string> fournisseurs proposables (activés et configurés). */
    public function enabledProviders(): array
    {
        return $this->registry->labels();
    }

    /**
     * @return string URL de redirection vers le fournisseur.
     * @throws SocialAuthException
     */
    public function begin(string $providerId, string $mode = SocialStateStore::MODE_LOGIN, int|string|null $userId = null): string
    {
        if ($mode === SocialStateStore::MODE_LINK) {
            if ($userId === null) {
                throw new SocialAuthException(SocialAuthException::LINK_SESSION_MISMATCH);
            }
            if (!$this->accounts->isUserEmailVerified($userId)) {
                throw new SocialAuthException(SocialAuthException::EMAIL_VERIFICATION_REQUIRED);
            }
        }

        $provider = $this->registry->get($providerId);
        $flow = $this->states->create($providerId, $mode, $userId, $provider->usesPkce(), $provider->usesNonce());
        $challenge = $flow->verifier === null ? null : SocialStateStore::challenge($flow->verifier);

        return $provider->authorizationUrl($flow->state, $flow->nonce, $challenge);
    }

    /**
     * @param array<string, mixed> $query Paramètres du callback (GET).
     * @param int|string|null $currentUserId Utilisateur actuellement connecté (mode association).
     * @throws SocialAuthException
     */
    public function complete(string $providerId, array $query, int|string|null $currentUserId = null): CallbackResult
    {
        $provider = $this->registry->get($providerId);

        if (isset($query['error'])) {
            try {
                $this->states->consume($providerId, $query['state'] ?? null); // consomme, sans rien exposer
            } catch (SocialAuthException) {
            }
            throw new SocialAuthException(SocialAuthException::PROVIDER_DENIED);
        }

        $code = $query['code'] ?? null;
        $flow = $this->states->consume($providerId, $query['state'] ?? null);
        if (!is_string($code) || $code === '' || strlen($code) > 2048) {
            throw new SocialAuthException(SocialAuthException::INVALID_REQUEST);
        }

        try {
            $tokens = $provider->exchangeCode($code, $flow->verifier);
            $identity = $provider->fetchIdentity($tokens, $flow->nonce);
        } catch (ProviderException $e) {
            throw new SocialAuthException(SocialAuthException::PROVIDER_ERROR, $e);
        }
        unset($tokens); // aucun jeton conservé après la connexion
        if ($identity->provider !== $providerId) {
            throw new SocialAuthException(SocialAuthException::INVALID_IDENTITY);
        }

        return $flow->mode === SocialStateStore::MODE_LINK
            ? $this->link($flow, $identity, $currentUserId)
            : $this->login($identity);
    }

    /** @throws SocialAuthException */
    public function unlink(int|string $userId, string $providerId): void
    {
        if (!in_array($providerId, $this->accounts->providersFor($userId), true)) {
            throw new SocialAuthException(SocialAuthException::PROVIDER_UNAVAILABLE);
        }
        if (!$this->accounts->canUnlink($userId, $providerId)) {
            throw new SocialAuthException(SocialAuthException::LAST_LOGIN_METHOD);
        }
        $this->accounts->unlinkIdentity($userId, $providerId);
    }

    private function login(ExternalIdentity $identity): CallbackResult
    {
        $userId = $this->accounts->findUserIdByIdentity($identity->provider, $identity->subject);
        $created = false;

        if ($userId !== null) {
            $user = $this->accounts->findUser($userId)
                ?? throw new SocialAuthException(SocialAuthException::INVALID_IDENTITY);
        } else {
            $user = $this->createAccount($identity);
            $created = true;
        }

        $this->gate->assertCanLogin($user);
        if ($this->gate->requiresTwoFactor($user)) {
            return new CallbackResult(
                CallbackResult::TWO_FACTOR_PENDING,
                $identity->provider,
                $user->getAuthIdentifier(),
                $user->getEmail(),
            );
        }

        $this->session->login($user);
        $this->accounts->touchLogin($identity->provider, $identity->subject);

        return new CallbackResult(
            $created ? CallbackResult::ACCOUNT_CREATED : CallbackResult::LOGGED_IN,
            $identity->provider,
            $user->getAuthIdentifier(),
            $user->getEmail(),
        );
    }

    private function createAccount(ExternalIdentity $identity): \Core\Security\Auth\AuthenticatableInterface
    {
        if ($this->policy !== SocialAccountPolicy::VERIFIED_EMAIL) {
            throw new SocialAuthException(SocialAuthException::REGISTRATION_DISABLED);
        }
        if ($identity->email === null || !$identity->emailVerified) {
            throw new SocialAuthException(SocialAuthException::EMAIL_UNVERIFIED);
        }
        // Jamais de rattachement automatique par e-mail : on refuse.
        if ($this->accounts->emailExists($identity->email)) {
            throw new SocialAuthException(SocialAuthException::ACCOUNT_EXISTS);
        }

        return $this->accounts->createUserWithIdentity($identity);
    }

    private function link(SocialFlow $flow, ExternalIdentity $identity, int|string|null $currentUserId): CallbackResult
    {
        if ($currentUserId === null || $flow->userId === null || (string) $flow->userId !== (string) $currentUserId) {
            throw new SocialAuthException(SocialAuthException::LINK_SESSION_MISMATCH);
        }
        if (!$this->accounts->isUserEmailVerified($currentUserId)) {
            throw new SocialAuthException(SocialAuthException::EMAIL_VERIFICATION_REQUIRED);
        }

        $owner = $this->accounts->findUserIdByIdentity($identity->provider, $identity->subject);
        if ($owner !== null) {
            if ((string) $owner !== (string) $currentUserId) {
                throw new SocialAuthException(SocialAuthException::IDENTITY_ALREADY_LINKED);
            }
        } else {
            if (in_array($identity->provider, $this->accounts->providersFor($currentUserId), true)) {
                throw new SocialAuthException(SocialAuthException::PROVIDER_ALREADY_LINKED);
            }
            $this->accounts->linkIdentity($currentUserId, $identity);
        }

        return new CallbackResult(CallbackResult::LINKED, $identity->provider, $currentUserId);
    }
}
