<?php

declare(strict_types=1);

namespace NM\Services\SocialAuth\Models;

use Core\Database\Model;

/**
 * Association (fournisseur, identifiant externe stable) → utilisateur local.
 * Aucun jeton n'est stocké.
 */
final class SocialIdentity extends Model
{
    protected static string $table = 'social_identities';

    public static function subjectHash(string $subject): string
    {
        return hash('sha256', $subject);
    }

    /**
     * Recherche via le hash (insensible aux collations SQL), puis comparaison
     * stricte et sensible à la casse du sujet : deux sujets ne différant que
     * par la casse ne sont jamais confondus.
     */
    public static function findByExternal(string $provider, string $subject): ?self
    {
        $row = static::where('provider', '=', $provider)
            ->where('subject_hash', '=', self::subjectHash($subject))
            ->first();

        return ($row instanceof self && hash_equals((string) $row->provider_user_id, $subject)) ? $row : null;
    }

    /** @return list<self> */
    public static function forUser(int|string $userId): array
    {
        /** @var list<self> $rows */
        $rows = static::where('user_id', '=', $userId)->get();

        return $rows;
    }
}
