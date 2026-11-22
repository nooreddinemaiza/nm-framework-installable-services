<?php

declare(strict_types=1);

namespace NM\Services\Messaging\Models;

use App\Models\Group;
use Core\Database\Model;
use NM\Services\Messaging\Models\ConversationUser;

final class Conversation extends Model
{
    protected static string $table = 'conversations';

    public function isParticipant(int $userId): bool
    {
        $groupId = $this->getAttribute('group_id');
        if ($groupId !== null) {
            $group = Group::find((int) $groupId);

            return $group instanceof Group
                && ($group->hasMember($userId) || $group->isOwnedBy($userId));
        }

        $isMember = ConversationUser::where('conversation_id', '=', (int) $this->getKey())
            ->where('user_id', '=', $userId)
            ->whereNull('left_at')
            ->exists();

        return $isMember;
    }

    /**
     * @return list<int>
     */
    public function participantIds(): array
    {
        $groupId = $this->getAttribute('group_id');
        if ($groupId !== null) {
            $group = Group::find((int) $groupId);
            if (!$group instanceof Group) {
                return [];
            }

            $participantIds = $group->memberIds();
            if ($group->ownerId() !== null) {
                $participantIds[] = $group->ownerId();
            }

            return array_values(array_unique(array_map('intval', $participantIds)));
        }

        return array_map(
            'intval',
            ConversationUser::where('conversation_id', '=', (int) $this->getKey())
                ->whereNull('left_at')
                ->pluck('user_id')
        );
    }

    public function isPreserved(): bool
    {
        return $this->getAttribute('preserved_at') !== null;
    }

    public function isActive(): bool
    {
        return $this->getAttribute('status') === 'active'
            && (
                $this->getAttribute('expires_at') === null
                || strtotime((string) $this->getAttribute('expires_at')) > time()
            );
    }

    /**
     * Canaux temps réel pour un message de cette conversation.
     * Groupe : un seul canal partagé. Direct : un canal par destinataire.
     *
     * @param list<int> $recipientIds destinataires (expéditeur exclu, actifs uniquement)
     * @return list<string>
     */
    public function realtimeChannels(array $recipientIds): array
    {
        if ($recipientIds === []) {
            return [];
        }

        $groupId = $this->getAttribute('group_id');
        if ($groupId !== null) {
            return ['messages.group.' . (int) $groupId];
        }

        return array_map(
            static fn(int $userId): string => 'messages.' . $userId,
            $recipientIds
        );
    }
}
