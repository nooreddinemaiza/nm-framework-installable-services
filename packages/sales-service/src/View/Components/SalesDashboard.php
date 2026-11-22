<?php

declare(strict_types=1);

namespace NM\Services\SalesService\View\Components;

use Core\View\Component\Component;

final class SalesDashboard extends Component
{
    protected function getViewName(): string
    {
        return 'sales.components.dashboard';
    }

    protected function getViewData(): array
    {
        $viewData = $this->data['viewData'] ?? null;
        if (!is_array($viewData)) {
            throw new \InvalidArgumentException('Sales dashboard component data is invalid.');
        }

        return $viewData;
    }
}
