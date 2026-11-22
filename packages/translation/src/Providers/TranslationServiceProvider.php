<?php

declare(strict_types=1);

namespace NM\Services\Translation\Providers;

use Core\Container\AbstractServiceProvider;
use Core\Http\SessionInterface;
use Core\Services\IndependentServiceRegistry;
use Core\Translation\LocaleManager;
use Core\Translation\Translator;
use Core\Translation\TranslatorInterface;
use InvalidArgumentException;
use NM\Services\Translation\Services\CompositeTranslator;
use NM\Services\Translation\Services\TranslationServiceState;

final class TranslationServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->singleton(TranslationServiceState::class, function (): TranslationServiceState {
            return new TranslationServiceState($this->app()->get(IndependentServiceRegistry::class));
        });
        $this->singleton(CompositeTranslator::class, function (): CompositeTranslator {
            $definition = $this->app()->get(IndependentServiceRegistry::class)->get('translation');
            $resources = $definition?->componentPath('resources');
            if ($resources === null) {
                throw new \RuntimeException('Les ressources du service de traduction sont introuvables.');
            }

            $frameworkTranslator = $this->app()->get(Translator::class);
            if (!$frameworkTranslator instanceof TranslatorInterface) {
                throw new \RuntimeException('Le traducteur du framework est invalide.');
            }
            $serviceTranslator = new Translator(
                $resources . DIRECTORY_SEPARATOR . 'lang',
                $frameworkTranslator->getLocale(),
                'fr-FR'
            );

            return new CompositeTranslator($frameworkTranslator, $serviceTranslator);
        });
        $this->singleton(TranslatorInterface::class, fn(): TranslatorInterface => $this->get(CompositeTranslator::class));
        $this->singleton(LocaleManager::class, function (): LocaleManager {
            $configuration = $this->app()->config('translation', []);
            if (!is_array($configuration)) {
                throw new InvalidArgumentException('La configuration de traduction doit être un tableau.');
            }
            $default = $this->app()->environment()->get(
                'APP_LOCALE',
                $configuration['default_locale'] ?? 'fr-FR'
            );
            $supported = ['ar-MA', 'fr-FR'];
            if (!is_string($default) || !in_array($default, $supported, true)) {
                throw new InvalidArgumentException('La locale par défaut doit être ar-MA ou fr-FR.');
            }

            return new LocaleManager(
                $this->app()->get(TranslatorInterface::class),
                $this->app()->get(SessionInterface::class),
                $default,
                $supported
            );
        });
    }
}
