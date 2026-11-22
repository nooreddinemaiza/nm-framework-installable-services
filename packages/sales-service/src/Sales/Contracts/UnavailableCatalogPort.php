<?php

declare(strict_types=1);

namespace NM\Services\SalesService\Sales\Contracts;

use NM\Services\SalesService\Sales\SalesContext;
use RuntimeException;

final class UnavailableCatalogPort implements CatalogPort
{
    public function findItem(SalesContext $context, string $reference): ?CatalogItem
    {
        unset($context, $reference);
        throw new RuntimeException('A Sales catalog connector is not configured for this application.');
    }
}
