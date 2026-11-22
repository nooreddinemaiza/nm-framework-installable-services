<?php

declare(strict_types=1);

namespace NM\Services\Messaging\Models;

use Core\Database\Model;
use NM\Services\Messaging\Models\ConversationGroupPolicy;

final class ConversationPolicy extends Model
{
    protected static string $table = 'conversation_role_policies';

    /**
     * @return array{message_limit: int, lifetime_minutes: int, image_streak_limit: int}
     */
    public static function directForRoles(int $starterRoleId, int $recipientRoleId): array
    {
        $policy = static::where('sr_id', '=', $starterRoleId)
            ->where('rr_id', '=', $recipientRoleId)
            ->first();

        return $policy === null
            ? self::directFallback()
            : self::normalize($policy->toArray());
    }

    /**
     * @return array{message_limit: int, lifetime_minutes: int, image_streak_limit: int}
     */
    public static function groupDefaults(): array
    {
        $policy = ConversationGroupPolicy::find(1);
        return $policy === null
            ? ['message_limit' => 500, 'lifetime_minutes' => 43200, 'image_streak_limit' => 3]
            : self::normalize($policy->toArray());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function directRows(): array
    {
        return array_map(
            static fn(self $policy): array => $policy->toArray(),
            static::table('conversation_role_policies as p')
                ->join('roles as s', 's.id', '=', 'p.sr_id')
                ->join('roles as r', 'r.id', '=', 'p.rr_id')
                ->select(
                    'p.id',
                    'p.sr_id',
                    'p.rr_id',
                    'p.message_limit',
                    'p.lifetime_minutes',
                    'p.image_streak_limit',
                    's.name as starter_role_name',
                    'r.name as recipient_role_name',
                    's.slug as starter_role_slug',
                    'r.slug as recipient_role_slug',
                )
                ->orderBy('s.level', 'DESC')
                ->orderBy('r.level', 'DESC')
                ->get()
        );
    }

    /**
     * @return array{message_limit: int, lifetime_minutes: int, image_streak_limit: int}
     */
    public static function directFallback(): array
    {
        $defaults = ConversationGroupPolicy::find(1);
        if ($defaults instanceof ConversationGroupPolicy) {
            return self::normalize([
                'message_limit' => $defaults->getAttribute('direct_message_limit'),
                'lifetime_minutes' => $defaults->getAttribute('direct_lifetime_minutes'),
                'image_streak_limit' => $defaults->getAttribute('direct_image_streak_limit'),
            ]);
        }

        return ['message_limit' => 100, 'lifetime_minutes' => 10080, 'image_streak_limit' => 3];
    }

    /**
     * @return array{
     *   message_limit: int,
     *   lifetime_minutes: int,
     *   image_streak_limit: int,
     *   fallback: array{message_limit: int, lifetime_minutes: int, image_streak_limit: int}
     * }
     */
    public static function adminDefaults(): array
    {
        return [
            ...self::groupDefaults(),
            'fallback' => self::directFallback(),
            'media_retention_minutes' => self::mediaRetentionMinutes(),
        ];
    }

    public static function mediaRetentionMinutes(): int
    {
        $policy = ConversationGroupPolicy::find(1);

        return max(1, min(525600, (int) ($policy?->getAttribute('media_retention_minutes') ?? 1440)));
    }

    /**
     * @param array<string, mixed> $values
     * @return array{message_limit: int, lifetime_minutes: int, image_streak_limit: int}
     */
    public static function normalize(array $values): array
    {
        return [
            'message_limit' => max(1, min(10000, (int) ($values['message_limit'] ?? 100))),
            'lifetime_minutes' => max(60, min(525600, (int) ($values['lifetime_minutes'] ?? 10080))),
            'image_streak_limit' => max(1, min(20, (int) ($values['image_streak_limit'] ?? 3))),
        ];
    }
}
