<?php

declare(strict_types=1);

namespace NM\Services\SocialAuth\Social;

/** Politique de création de compte pour une identité inconnue. */
enum SocialAccountPolicy: string
{
    /** Aucun compte créé : la connexion est refusée (défaut). */
    case DENY = 'deny';
    /** Création uniquement si le fournisseur garantit un e-mail vérifié et inconnu localement. */
    case VERIFIED_EMAIL = 'verified_email';

    public static function fromConfig(mixed $value): self
    {
        return self::tryFrom(is_string($value) ? strtolower(trim($value)) : '') ?? self::DENY;
    }
}
