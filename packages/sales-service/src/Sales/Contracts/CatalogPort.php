<?php

declare(strict_types=1);

namespace NM\Services\SalesService\Sales\Contracts;

use NM\Services\SalesService\Sales\SalesContext;
use NM\Services\SalesService\Sales\Domain\Money;

interface CatalogPort
{
    public function findItem(SalesContext $context, string $reference): ?CatalogItem;
}
