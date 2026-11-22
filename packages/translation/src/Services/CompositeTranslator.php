<?php

declare(strict_types=1);

namespace NM\Services\Translation\Services;

use Core\Translation\TranslatorInterface;

final class CompositeTranslator implements TranslatorInterface
{
    public function __construct(
        private readonly TranslatorInterface $frameworkTranslator,
        private readonly TranslatorInterface $serviceTranslator,
    ) {
    }

    public function get(string $key, array $replace = [], ?string $locale = null): string
    {
        if ($this->serviceHas($key, $locale)) {
            return $this->serviceTranslator->get($key, $replace, $locale);
        }

        return $this->frameworkTranslator->get($key, $replace, $locale);
    }

    public function has(string $key, ?string $locale = null): bool
    {
        return $this->serviceHas($key, $locale)
            || $this->frameworkTranslator->has($key, $locale);
    }

    public function all(?string $locale = null): array
    {
        return array_replace(
            $this->frameworkTranslator->all($locale),
            $this->serviceTranslator->all($locale)
        );
    }

    public function setLocale(string $locale): void
    {
        $this->frameworkTranslator->setLocale($locale);
        $this->serviceTranslator->setLocale($locale);
    }

    public function getLocale(): string
    {
        return $this->frameworkTranslator->getLocale();
    }

    private function serviceHas(string $key, ?string $locale): bool
    {
        return $this->serviceTranslator->has($key, $locale)
            || ($locale !== 'fr-FR' && $this->serviceTranslator->has($key, 'fr-FR'));
    }
}
