<?php

declare(strict_types=1);

namespace NM\Services\PageManagement\Pages;

use Core\Events\Event;

final class PageManagementEvent extends Event
{
    public function __construct(
        public readonly string $name,
        public readonly ?int $pageId = null,
        public readonly array $context = [],
    ) {
    }
}
