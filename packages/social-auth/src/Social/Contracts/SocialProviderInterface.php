<?php

declare(strict_types=1);

namespace NM\Services\SocialAuth\Social\Contracts;

use NM\Services\SocialAuth\Social\ExternalIdentity;
use NM\Services\SocialAuth\Social\TokenSet;

/**
 * Contrat commun des adaptateurs. Le service d'orchestration ne connaît que lui.
 */
interface SocialProviderInterface
{
    /** Identifiant stable et unique (ex. "google"), égal à la clé du registre. */
    public function id(): string;

    public function label(): string;

    public function usesPkce(): bool;

    /** Vrai pour OpenID Connect : un nonce est alors généré puis vérifié. */
    public function usesNonce(): bool;

    /** URL de redirection vers le fournisseur (flux code d'autorisation uniquement). */
    public function authorizationUrl(string $state, ?string $nonce, ?string $codeChallenge): string;

    /** Échange serveur-à-serveur du code contre des jetons. */
    public function exchangeCode(string $code, ?string $codeVerifier): TokenSet;

    /**
     * Identité vérifiée et normalisée, obtenue par le canal serveur du fournisseur.
     *
     * @throws \NM\Services\SocialAuth\Social\Exceptions\ProviderException
     * @throws \NM\Services\SocialAuth\Social\Exceptions\SocialAuthException
     */
    public function fetchIdentity(TokenSet $tokens, ?string $expectedNonce): ExternalIdentity;
}
