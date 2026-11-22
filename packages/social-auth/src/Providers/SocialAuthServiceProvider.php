<?php

declare(strict_types=1);

namespace NM\Services\SocialAuth\Providers;

use Core\Database\ConnectionManager;
use Core\Security\Auth\AuthManager;
use Core\Services\IndependentServiceRegistry;
use Core\Container\AbstractServiceProvider;
use NM\Services\SocialAuth\Social\AccountGate;
use NM\Services\SocialAuth\Social\Contracts\FlowStorageInterface;
use NM\Services\SocialAuth\Social\Contracts\HttpClientInterface;
use NM\Services\SocialAuth\Social\Contracts\LocalSessionInterface;
use NM\Services\SocialAuth\Social\Contracts\SocialAccountRepositoryInterface;
use NM\Services\SocialAuth\Social\GuardLocalSession;
use NM\Services\SocialAuth\Social\Http\CurlHttpClient;
use NM\Services\SocialAuth\Social\ModelSocialAccountRepository;
use NM\Services\SocialAuth\Social\ProviderRegistry;
use NM\Services\SocialAuth\Social\SessionFlowStorage;
use NM\Services\SocialAuth\Social\SocialAccountPolicy;
use NM\Services\SocialAuth\Social\SocialAuthService;
use NM\Services\SocialAuth\Social\SocialAuthServiceState;
use NM\Services\SocialAuth\Social\SocialProviderCatalog;
use NM\Services\SocialAuth\Social\SocialProviderSettingsStore;
use NM\Services\SocialAuth\Social\SocialStateStore;

final class SocialAuthServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $config = $this->configuration();

        $this->singleton(HttpClientInterface::class, static fn(): HttpClientInterface => new CurlHttpClient());
        $this->singleton(FlowStorageInterface::class, function (): FlowStorageInterface {
            return new SessionFlowStorage($this->app()->session());
        });
        $this->singleton(SocialAccountRepositoryInterface::class, function (): SocialAccountRepositoryInterface {
            return new ModelSocialAccountRepository(
                $this->app()->get(ConnectionManager::class)->connection()
            );
        });
        $this->singleton(LocalSessionInterface::class, function (): LocalSessionInterface {
            return new GuardLocalSession($this->app()->get(AuthManager::class));
        });
        $this->singleton(AccountGate::class, static fn(): AccountGate => new AccountGate($config['skip_two_factor']));
        $this->singleton(ProviderRegistry::class, function () use ($config): ProviderRegistry {
            $providers = $config['providers'];
            $overrides = (new SocialProviderSettingsStore(
                $this->app()->get(ConnectionManager::class)->connection()
            ))->overrides();
            foreach ($overrides as $id => $settings) {
                foreach (['enabled', 'client_id', 'redirect_uri'] as $field) {
                    if (array_key_exists($field, $settings)
                        && !($field === 'redirect_uri' && $settings[$field] === '')) {
                        $providers[$id][$field] = $settings[$field];
                    }
                }
            }

            return new ProviderRegistry(
                $providers,
                $this->get(HttpClientInterface::class),
            );
        });
        $this->singleton(SocialStateStore::class, function () use ($config): SocialStateStore {
            return new SocialStateStore(
                $this->get(FlowStorageInterface::class),
                $config['state_ttl'],
            );
        });
        $this->singleton(SocialAuthService::class, function () use ($config): SocialAuthService {
            return new SocialAuthService(
                $this->get(ProviderRegistry::class),
                $this->get(SocialStateStore::class),
                $this->get(SocialAccountRepositoryInterface::class),
                $this->get(AccountGate::class),
                $this->get(LocalSessionInterface::class),
                SocialAccountPolicy::fromConfig($config['account_creation']),
            );
        });
        $this->singleton(SocialAuthServiceState::class, function (): SocialAuthServiceState {
            return new SocialAuthServiceState($this->app()->get(IndependentServiceRegistry::class));
        });
    }

    /**
     * @return array{
     *   account_creation: string,
     *   skip_two_factor: bool,
     *   state_ttl: int,
     *   providers: array<string, array<string, mixed>>
     * }
     */
    private function configuration(): array
    {
        $env = fn(string $key, mixed $default = null): mixed => $this->app()->env($key, $default);
        $bool = static fn(mixed $value): bool => filter_var($value, FILTER_VALIDATE_BOOLEAN);
        $base = rtrim((string) $env('APP_URL', ''), '/');
        $provider = static function (
            string $id,
            string $class,
            string $label,
            bool $pkce,
            array $options = [],
        ) use ($env, $bool, $base): array {
            $prefix = 'SOCIAL_' . strtoupper($id) . '_';

            return [
                'class' => $class,
                'label' => $label,
                'enabled' => $bool($env($prefix . 'ENABLED', false)),
                'client_id' => (string) $env($prefix . 'CLIENT_ID', ''),
                'client_secret' => (string) $env($prefix . 'CLIENT_SECRET', ''),
                'redirect_uri' => (string) $env(
                    $prefix . 'REDIRECT_URI',
                    $base . '/connexion/social/' . $id . '/callback'
                ),
                'pkce' => $bool($env($prefix . 'PKCE', $pkce)),
                'scopes' => [],
                'options' => $options,
            ];
        };

        $providers = [];
        foreach (SocialProviderCatalog::PROVIDERS as $id => $definition) {
            $options = match ($id) {
                'microsoft' => ['tenant' => (string) $env('SOCIAL_MICROSOFT_TENANT', 'common')],
                'facebook' => ['graph_version' => (string) $env('SOCIAL_FACEBOOK_GRAPH_VERSION', 'v21.0')],
                default => [],
            };
            $providers[$id] = $provider(
                $id,
                $definition['class'],
                $definition['label'],
                $definition['pkce'],
                $options,
            );
        }
        $customProviders = $this->app()->config('social.providers', []);
        if (is_array($customProviders)) {
            $providers = array_replace($providers, $customProviders);
        }

        return [
            'account_creation' => (string) $env('SOCIAL_ACCOUNT_CREATION', 'deny'),
            'skip_two_factor' => $bool($env('SOCIAL_SKIP_TWO_FACTOR', false)),
            'state_ttl' => max(60, min(900, (int) $env('SOCIAL_STATE_TTL', 600))),
            'providers' => $providers,
        ];
    }
}
