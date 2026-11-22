<?php

declare(strict_types=1);

namespace NM\Services\Articles\Services;

final class ArticleContentSanitizer
{
    public static function sanitize(string $html, bool $rejectInvalidInlineImages = false): string
    {
        return RichTextSanitizer::sanitize($html, $rejectInvalidInlineImages);
    }

    public static function hasContent(string $html): bool
    {
        return RichTextSanitizer::hasContent($html);
    }
}
