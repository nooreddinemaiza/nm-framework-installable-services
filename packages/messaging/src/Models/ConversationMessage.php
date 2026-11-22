<?php

declare(strict_types=1);

namespace NM\Services\Messaging\Models;

use Core\Database\Model;

final class ConversationMessage extends Model
{
    protected static string $table = 'messages';

    /**
     * @return list<static>
     */
    public static function forConversation(int $conversationId, int $limit = 101, ?int $beforeId = null): array
    {
        $query = static::table('messages as m')
            ->join('users as u', 'u.id', '=', 'm.sender_id')
            ->select(
                'm.id',
                'm.conversation_id',
                'm.sender_id',
                'm.body',
                'm.message_type',
                'm.media_path',
                'm.media_mime_type',
                'm.media_size',
                'm.media_preserved',
                'm.media_deleted_at',
                'm.media_expires_at',
                'm.created_at',
                'm.edited_at',
                'u.name as sender_name',
                'u.username as sender_username',
            )
            ->where('m.conversation_id', '=', $conversationId)
            ->orderBy('m.id', 'DESC');
        if ($beforeId !== null) {
            $query->where('m.id', '<', $beforeId);
        }
        $messages = $query->limit(min(200, max(1, $limit)))->get();

        return array_reverse($messages);
    }
}
