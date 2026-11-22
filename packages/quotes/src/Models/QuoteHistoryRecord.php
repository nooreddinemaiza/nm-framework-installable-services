<?php

declare(strict_types=1);

namespace NM\Services\Quotes\Models;

use Core\Database\Model;

final class QuoteHistoryRecord extends Model
{
    protected static string $table = 'quote_histories';
    protected static bool $timestamps = false;
}
