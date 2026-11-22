<?php

declare(strict_types=1);

namespace NM\Services\Messaging\Models;

use Core\Database\Model;

final class ConversationMessageReceipt extends Model
{
    protected static string $table = 'conversation_message_receipts';

    /**
     * Base commune : reçus non lus de l'utilisateur, limités aux conversations
     * auxquelles il participe encore (direct non quitté / groupe membre ou owner).
     * Alias disponibles : r (reçu), m (message), c (conversation).
     */
    private static function unreadBase(int $userId)
    {
        return self::table('conversation_message_receipts as r')
            ->join('messages as m', 'm.id', '=', 'r.message_id')
            ->leftJoin('conversation_user as cu', function ($join) use ($userId): void {
                $join->on('cu.conversation_id', '=', 'm.conversation_id')
                    ->where('cu.user_id', '=', $userId)
                    ->whereRaw('cu.left_at IS NULL');
            })
            ->join('conversations as c', 'c.id', '=', 'm.conversation_id')
            ->leftJoin('groups as g', 'g.id', '=', 'c.group_id')
            ->leftJoin('group_user as gu', function ($join) use ($userId): void {
                $join->on('gu.group_id', '=', 'c.group_id')
                    ->where('gu.user_id', '=', $userId);
            })
            ->where('r.user_id', '=', $userId)
            ->whereNull('r.seen_at')
            ->where(function ($scope) use ($userId): void {
                $scope->where(function ($direct): void {
                    $direct->whereNull('c.group_id')->whereNotNull('cu.user_id');
                })->orWhere(function ($group) use ($userId): void {
                    $group->whereNotNull('c.group_id')->where(function ($member) use ($userId): void {
                        $member->whereNotNull('gu.user_id')->orWhere('g.owner_id', '=', $userId);
                    });
                });
            });
    }

    public static function unreadReceiptCount(int $userId, ?int $conversationId = null): int
    {
        $query = self::unreadBase($userId);
        if ($conversationId !== null) {
            $query->where('m.conversation_id', '=', $conversationId);
        }

        return $query->count();
    }

    /**
     * Derniers messages non lus, du plus récent au plus ancien.
     *
     * @return list<static> lignes avec : id, conversation_id, sender_id, body,
     *                      message_type, created_at, is_group, subject, sender_name
     */
    public static function latestUnread(int $userId, int $limit = 5): array
    {
        $limit = max(1, min(200, $limit));

        return self::unreadBase($userId)
            ->join('users as u', 'u.id', '=', 'm.sender_id')
            ->select(
                'm.id',
                'm.conversation_id',
                'm.sender_id',
                'm.body',
                'm.message_type',
                'm.created_at',
                'c.is_group',
                'c.subject',
                'u.name as sender_name',
            )
            ->orderBy('m.id', 'DESC')
            ->limit($limit)
            ->get();
    }

    /**
     * Ids (uniques) des conversations ayant au moins un message non lu.
     *
     * @return list<int>
     */
    public static function unreadConversationIds(int $userId): array
    {
        $ids = self::unreadBase($userId)->pluck('m.conversation_id');

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * @param list<int> $conversationIds
     * @return array<int, int> conversation_id => non lus
     */
    public static function unreadCountsByConversation(int $userId, array $conversationIds): array
    {
        if ($conversationIds === []) {
            return [];
        }

        $ids = self::table('conversation_message_receipts as r')
            ->join('messages as m', 'm.id', '=', 'r.message_id')
            ->where('r.user_id', '=', $userId)
            ->whereNull('r.seen_at')
            ->whereIn('m.conversation_id', $conversationIds)
            ->pluck('m.conversation_id');

        return array_count_values(array_map('intval', $ids));
    }
}