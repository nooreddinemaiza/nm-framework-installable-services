<?php

declare(strict_types=1);

namespace NM\Services\Articles\Models;

use Core\Database\Model;

final class Article extends Model
{
    protected static string $table = 'articles';
    protected static bool $timestamps = true;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    public function isPublished(): bool
    {
        return $this->getAttribute('status') === self::STATUS_PUBLISHED;
    }

    public function isDraft(): bool
    {
        return $this->getAttribute('status') === self::STATUS_DRAFT;
    }
}
