<?php

declare(strict_types=1);

namespace NM\Services\SocialAuth\Social\Exceptions;

/**
 * Refus explicite et sûr : seul un message générique prédéfini est exposé,
 * jamais de code d'autorisation, de jeton ni de détail du fournisseur.
 */
final class SocialAuthException extends \RuntimeException
{
    public const PROVIDER_UNAVAILABLE = 'provider_unavailable';
    public const STATE_INVALID = 'state_invalid';
    public const PROVIDER_DENIED = 'provider_denied';
    public const INVALID_REQUEST = 'invalid_request';
    public const PROVIDER_ERROR = 'provider_error';
    public const INVALID_IDENTITY = 'invalid_identity';
    public const REGISTRATION_DISABLED = 'registration_disabled';
    public const EMAIL_UNVERIFIED = 'email_unverified';
    public const EMAIL_VERIFICATION_REQUIRED = 'email_verification_required';
    public const ACCOUNT_EXISTS = 'account_exists';
    public const ACCOUNT_LOCKED = 'account_locked';
    public const ACCOUNT_BLOCKED = 'account_blocked';
    public const ACCOUNT_INACTIVE = 'account_inactive';
    public const TWO_FACTOR_REQUIRED = 'two_factor_required';
    public const IDENTITY_ALREADY_LINKED = 'identity_already_linked';
    public const PROVIDER_ALREADY_LINKED = 'provider_already_linked';
    public const LINK_SESSION_MISMATCH = 'link_session_mismatch';
    public const LAST_LOGIN_METHOD = 'last_login_method';
    public const ACCOUNT_CREATION_FAILED = 'account_creation_failed';

    private const MESSAGES = [
        self::PROVIDER_UNAVAILABLE => "Ce mode de connexion n'est pas disponible.",
        self::STATE_INVALID => 'Demande de connexion invalide ou expirée. Veuillez réessayer.',
        self::PROVIDER_DENIED => 'La connexion a été annulée ou refusée par le fournisseur.',
        self::INVALID_REQUEST => 'Réponse du fournisseur invalide. Veuillez réessayer.',
        self::PROVIDER_ERROR => 'Le fournisseur est momentanément indisponible. Veuillez réessayer.',
        self::INVALID_IDENTITY => "Le fournisseur n'a pas fourni une identité exploitable.",
        self::REGISTRATION_DISABLED => "Aucun compte n'est associé à cette identité et la création de compte est désactivée.",
        self::EMAIL_UNVERIFIED => "Une adresse e-mail vérifiée est requise pour créer un compte avec ce fournisseur.",
        self::EMAIL_VERIFICATION_REQUIRED => 'Vérifiez votre adresse e-mail avant d’associer un compte externe.',
        self::ACCOUNT_EXISTS => "Un compte existe déjà pour cette adresse e-mail. Connectez-vous avec votre mot de passe, puis associez ce fournisseur depuis la page Sécurité de votre compte.",
        self::ACCOUNT_LOCKED => 'Compte temporairement bloqué. Réessayez plus tard.',
        self::ACCOUNT_BLOCKED => "Ce compte a été bloqué par l'administrateur.",
        self::ACCOUNT_INACTIVE => "Ce compte est désactivé. Utilisez la connexion classique pour le restaurer.",
        self::TWO_FACTOR_REQUIRED => 'Ce compte exige une vérification par double authentification.',
        self::IDENTITY_ALREADY_LINKED => 'Ce compte externe est déjà associé à un autre utilisateur.',
        self::PROVIDER_ALREADY_LINKED => 'Un compte de ce fournisseur est déjà associé à votre compte.',
        self::LINK_SESSION_MISMATCH => 'Votre session a changé pendant l’association. Veuillez réessayer.',
        self::LAST_LOGIN_METHOD => "Impossible de dissocier votre seul moyen de connexion : définissez d'abord un mot de passe.",
        self::ACCOUNT_CREATION_FAILED => "Le compte n'a pas pu être créé. Veuillez réessayer.",
    ];

    public function __construct(public readonly string $reason, ?\Throwable $previous = null)
    {
        parent::__construct(self::MESSAGES[$reason] ?? self::MESSAGES[self::PROVIDER_ERROR], 0, $previous);
    }

    public function publicMessage(): string
    {
        return $this->getMessage();
    }
}
