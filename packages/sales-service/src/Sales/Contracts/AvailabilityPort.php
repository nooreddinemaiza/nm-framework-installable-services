<?php

declare(strict_types=1);

namespace NM\Services\SalesService\Sales\Contracts;

use NM\Services\SalesService\Sales\SalesContext;

interface AvailabilityPort
{
    public function assertAvailable(SalesContext $context, CatalogItem $item, string $quantity): void;
}
