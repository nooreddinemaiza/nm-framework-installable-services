<?php

declare(strict_types=1);

namespace NM\Services\Messaging\Models;

use Core\Database\Model;

final class ConversationUser extends Model
{
    protected static string $table = 'conversation_user';
}
