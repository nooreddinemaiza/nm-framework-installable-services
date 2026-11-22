<?php

declare(strict_types=1);

namespace NM\Services\PageManagement\Seo;

use App\Seo\SitemapProviderInterface;
use NM\Services\PageManagement\Pages\PageManagementService;
use NM\Services\PageManagement\Services\PageManagementState;

final class PageManagementSitemapProvider implements SitemapProviderInterface
{
    public function __construct(
        private readonly PageManagementService $pages,
        private readonly PageManagementState $serviceState,
    ) {
    }

    public function entries(): iterable
    {
        if (!$this->serviceState->isEnabled()) {
            return [];
        }

        return $this->pages->publicSitemapEntries();
    }
}
