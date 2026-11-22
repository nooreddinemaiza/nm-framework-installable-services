<?php

declare(strict_types=1);

namespace NM\Services\SocialAuth\Social;

use NM\Services\SocialAuth\Social\Contracts\SocialAccountRepositoryInterface;
use NM\Services\SocialAuth\Social\Exceptions\SocialAuthException;
use App\Models\Role;
use App\Models\User;
use Core\Database\Connection;
use Core\Security\Auth\AuthenticatableInterface;
use Core\Security\Hasher;
use NM\Services\SocialAuth\Models\SocialIdentity;

final class ModelSocialAccountRepository implements SocialAccountRepositoryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function findUserIdByIdentity(string $provider, string $subject): int|string|null
    {
        $row = SocialIdentity::findByExternal($provider, $subject);

        return $row === null ? null : $row->user_id;
    }

    public function findUser(int|string $id): ?AuthenticatableInterface
    {
        return User::find($id);
    }

    public function isUserEmailVerified(int|string $id): bool
    {
        return User::find($id)?->isEmailVerified() ?? false;
    }

    public function emailExists(string $email): bool
    {
        return User::where('email', '=', $email)->exists();
    }

    public function createUserWithIdentity(ExternalIdentity $identity): AuthenticatableInterface
    {
        $user = null;
        try {
            // Transaction du framework (savepoint si déjà imbriquée) : compte + association, ou rien.
            $this->connection->transaction(function () use ($identity, &$user): void {
                $now = date('Y-m-d H:i:s');
                $user = User::create([
                    'name' => $identity->name ?? $this->nameFromEmail((string) $identity->email),
                    'email' => $identity->email,
                    'username' => $this->uniqueUsername((string) $identity->email),
                    // Mot de passe aléatoire inconnu de tous : la connexion classique reste impossible
                    // tant que l'utilisateur n'en définit pas un (réinitialisation par e-mail).
                    'password' => (new Hasher())->hash(bin2hex(random_bytes(32))),
                    'is_active' => 1,
                    'email_verified_at' => $now,
                ]);
                $role = Role::where('slug', '=', User::MEMBER_SLUG)->first();
                if ($role !== null) {
                    $user->assignRole($role);
                }
                $this->insertIdentity($user->getKey(), $identity, true);
            });
        } catch (\Throwable $e) {
            throw $this->isConflict($e)
                ? new SocialAuthException(SocialAuthException::ACCOUNT_CREATION_FAILED, $e)
                : $e;
        }

        return $user;
    }

    public function linkIdentity(int|string $userId, ExternalIdentity $identity): void
    {
        try {
            $this->insertIdentity($userId, $identity, false);
        } catch (\Throwable $e) {
            throw $this->isConflict($e)
                ? new SocialAuthException(SocialAuthException::IDENTITY_ALREADY_LINKED, $e)
                : $e;
        }
    }

    public function touchLogin(string $provider, string $subject): void
    {
        SocialIdentity::findByExternal($provider, $subject)?->update(['last_login_at' => date('Y-m-d H:i:s')]);
    }

    public function providersFor(int|string $userId): array
    {
        return array_map(static fn(SocialIdentity $i): string => (string) $i->provider, SocialIdentity::forUser($userId));
    }

    public function canUnlink(int|string $userId, string $provider): bool
    {
        $rows = SocialIdentity::forUser($userId);
        if (count($rows) > 1) {
            return true;
        }
        foreach ($rows as $row) {
            if ($row->provider === $provider && (int) $row->created_account === 1) {
                // Compte créé via ce fournisseur : il faut d'abord avoir défini un mot de passe.
                return User::find($userId)?->getAttribute('password_changed') !== null;
            }
        }

        return true;
    }

    public function unlinkIdentity(int|string $userId, string $provider): bool
    {
        foreach (SocialIdentity::forUser($userId) as $row) {
            if ($row->provider === $provider) {
                return $row->delete();
            }
        }

        return false;
    }

    private function insertIdentity(int|string $userId, ExternalIdentity $i, bool $created): void
    {
        SocialIdentity::create([
            'user_id' => $userId,
            'provider' => $i->provider,
            'provider_user_id' => $i->subject,
            'subject_hash' => SocialIdentity::subjectHash($i->subject),
            'created_account' => $created ? 1 : 0,
            'last_login_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function isConflict(\Throwable $e): bool
    {
        // SQLSTATE classe 23 = violation d'intégrité (contrainte d'unicité, etc.).
        for (; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof \PDOException && str_starts_with((string) $e->getCode(), '23')) {
                return true;
            }
        }

        return false;
    }

    private function nameFromEmail(string $email): string
    {
        return ucfirst(strstr($email, '@', true) ?: 'Utilisateur');
    }

    private function uniqueUsername(string $email): string
    {
        $base = preg_replace('/[^a-z0-9_]+/', '', strtolower(strstr($email, '@', true) ?: 'user')) ?: 'user';
        $base = substr($base, 0, 20);
        $candidate = $base;
        for ($i = 0; $i < 5 && User::where('username', '=', $candidate)->exists(); $i++) {
            $candidate = $base . random_int(100, 99999);
        }

        return User::where('username', '=', $candidate)->exists() ? $base . bin2hex(random_bytes(4)) : $candidate;
    }
}
