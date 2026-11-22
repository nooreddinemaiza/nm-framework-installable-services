<?php

declare(strict_types=1);

namespace NM\Services\SalesService\View\Components;

use Core\View\Component\Component;

final class SalesOrderConfirmation extends Component
{
    protected function getViewName(): string
    {
        return 'sales.components.order-confirmation';
    }

    protected function getViewData(): array
    {
        $viewData = $this->data['viewData'] ?? null;
        if (!is_array($viewData)) {
            throw new \InvalidArgumentException('Sales order confirmation data is invalid.');
        }

        return $viewData;
    }
}
