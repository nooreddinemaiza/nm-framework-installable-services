<?php

declare(strict_types=1);

namespace NM\Services\SocialAuth\Social\Exceptions;

/**
 * Erreur technique côté fournisseur (réseau, réponse invalide).
 * INTERNE : son message ne doit jamais contenir de jeton ni de secret et
 * n'est jamais affiché à l'utilisateur (voir SocialAuthException).
 */
final class ProviderException extends \RuntimeException
{
}
