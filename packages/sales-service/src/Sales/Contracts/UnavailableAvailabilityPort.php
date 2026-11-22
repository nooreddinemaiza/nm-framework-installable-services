<?php

declare(strict_types=1);

namespace NM\Services\SalesService\Sales\Contracts;

use NM\Services\SalesService\Sales\SalesContext;
use RuntimeException;

final class UnavailableAvailabilityPort implements AvailabilityPort
{
    public function assertAvailable(SalesContext $context, CatalogItem $item, string $quantity): void
    {
        unset($context, $item, $quantity);
        throw new RuntimeException('A Sales availability connector is not configured for this application.');
    }
}
