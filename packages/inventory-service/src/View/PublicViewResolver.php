<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\View;

use Core\View\ViewFactory;

final class PublicViewResolver
{
    public function __construct(private readonly ViewFactory $factory) {}

    public function resolve(string $view): string
    {
        $version = strtolower((string) app()->environment()->get('PUBLIC_VIEWS_VERSION', 'v2'));
        if ($version !== 'v1') {
            return $view;
        }

        $variants = [
            'public.catalog.product' => 'views.public.catalog.product-v1',
        ];

        return $variants[$view] ?? $view;
    }

    public function render(string $view, array $data = []): \Core\Http\Response
    {
        return new \Core\Http\Response($this->factory->render($this->resolve($view), $data));
    }
}
