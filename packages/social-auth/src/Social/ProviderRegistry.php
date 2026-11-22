<?php

declare(strict_types=1);

namespace NM\Services\SocialAuth\Social;

use NM\Services\SocialAuth\Social\Contracts\HttpClientInterface;
use NM\Services\SocialAuth\Social\Contracts\SocialProviderInterface;
use NM\Services\SocialAuth\Social\Exceptions\SocialAuthException;

/**
 * Registre/factory : résout un fournisseur ACTIVÉ ET CONFIGURÉ par son
 * identifiant. Ajouter un fournisseur = une entrée de définition, sans
 * toucher au service ni au cœur.
 *
 * Définition : [
 *   'class' => class-string<SocialProviderInterface>   (ou 'factory' => fn(ProviderConfig, HttpClientInterface): SocialProviderInterface),
 *   'label', 'enabled', 'client_id', 'client_secret', 'redirect_uri', 'scopes', 'pkce', 'options'
 * ]
 */
final class ProviderRegistry
{
    private const ID_PATTERN = '/^[a-z0-9_-]{1,32}$/';

    /** @var array<string, SocialProviderInterface> */
    private array $instances = [];

    /** @param array<string, array<string, mixed>> $definitions */
    public function __construct(
        private array $definitions,
        private readonly HttpClientInterface $http,
    ) {
    }

    /** @param array<string, mixed> $definition */
    public function register(string $id, array $definition): void
    {
        $this->definitions[$id] = $definition;
        unset($this->instances[$id]);
    }

    public function isAvailable(string $id): bool
    {
        try {
            $this->get($id);

            return true;
        } catch (SocialAuthException) {
            return false;
        }
    }

    /** @return array<string, string> id => libellé, fournisseurs réellement utilisables uniquement. */
    public function labels(): array
    {
        $out = [];
        foreach (array_keys($this->definitions) as $id) {
            $id = (string) $id;
            if ($this->isAvailable($id)) {
                $out[$id] = $this->get($id)->label();
            }
        }

        return $out;
    }

    /** @throws SocialAuthException PROVIDER_UNAVAILABLE (inconnu, désactivé, mal configuré). */
    public function get(string $id): SocialProviderInterface
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        $unavailable = new SocialAuthException(SocialAuthException::PROVIDER_UNAVAILABLE);
        $def = preg_match(self::ID_PATTERN, $id) === 1 ? ($this->definitions[$id] ?? null) : null;

        if (!is_array($def) || ($def['enabled'] ?? false) !== true) {
            throw $unavailable;
        }

        $config = new ProviderConfig(
            id: $id,
            label: (string) ($def['label'] ?? ucfirst($id)),
            clientId: (string) ($def['client_id'] ?? ''),
            clientSecret: (string) ($def['client_secret'] ?? ''),
            redirectUri: (string) ($def['redirect_uri'] ?? ''),
            scopes: array_values(array_map('strval', (array) ($def['scopes'] ?? []))),
            pkce: (bool) ($def['pkce'] ?? true),
            options: (array) ($def['options'] ?? []),
        );
        if (!$config->isComplete()) {
            throw $unavailable;
        }

        if (isset($def['factory']) && $def['factory'] instanceof \Closure) {
            $provider = ($def['factory'])($config, $this->http);
        } elseif (isset($def['class']) && is_string($def['class']) && is_subclass_of($def['class'], SocialProviderInterface::class)) {
            $provider = new ($def['class'])($config, $this->http);
        } else {
            throw $unavailable;
        }

        if (!$provider instanceof SocialProviderInterface || $provider->id() !== $id) {
            throw $unavailable;
        }

        return $this->instances[$id] = $provider;
    }
}
