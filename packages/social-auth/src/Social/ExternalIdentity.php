<?php

declare(strict_types=1);

namespace NM\Services\SocialAuth\Social;

use NM\Services\SocialAuth\Social\Exceptions\SocialAuthException;

/**
 * Identité normalisée, indépendante du fournisseur. La clé d'identité est
 * (provider, subject) ; l'e-mail n'en fait JAMAIS partie.
 */
final class ExternalIdentity
{
    public readonly string $subject;
    public readonly ?string $email;
    public readonly bool $emailVerified;
    public readonly ?string $name;

    public function __construct(
        public readonly string $provider,
        string|int $subject,
        ?string $email = null,
        bool $emailVerified = false,
        ?string $name = null,
    ) {
        $subject = trim((string) $subject);
        if ($subject === '' || strlen($subject) > 191 || preg_match('/[\x00-\x1F\x7F]/', $subject) === 1) {
            throw new SocialAuthException(SocialAuthException::INVALID_IDENTITY);
        }
        $this->subject = $subject;

        $email = $email === null ? null : strtolower(trim($email));
        $valid = $email !== null && strlen($email) <= 190 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        $this->email = $valid ? $email : null;
        $this->emailVerified = $valid && $emailVerified;

        $name = $name === null ? null : trim(preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '');
        $this->name = ($name === null || $name === '') ? null : (function_exists('mb_substr') ? mb_substr($name, 0, 120) : substr($name, 0, 120));
    }
}
