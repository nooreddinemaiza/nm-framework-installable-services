<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Services;

use Core\Services\IndependentServiceRegistry;

final class InventoryServiceState
{
    public function __construct(private readonly IndependentServiceRegistry $services)
    {
    }

    public function isEnabled(): bool
    {
        return $this->services->status('inventory-service') === 'active';
    }
}