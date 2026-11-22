<?php

declare(strict_types=1);

namespace NM\Services\OnlinePayments\OnlinePayments;

use NM\Services\OnlinePayments\OnlinePayments\Contracts\PaymentProviderPort;
use InvalidArgumentException;

final class PaymentProviderRegistry
{
    /** @var array<string, PaymentProviderPort> */
    private array $providers = [];

    /**
     * @param iterable<PaymentProviderPort> $providers
     */
    public function __construct(iterable $providers = [])
    {
        foreach ($providers as $provider) {
            $this->register($provider);
        }
    }

    public function register(PaymentProviderPort $provider): void
    {
        $code = strtolower(trim($provider->code()));
        $name = trim($provider->name());
        if (preg_match('/^[a-z][a-z0-9._-]{0,59}$/D', $code) !== 1
            || $name === ''
            || strlen($name) > 120
            || preg_match('/[\r\n\0]/', $name) === 1) {
            throw new InvalidArgumentException('Le fournisseur de paiement possède une identité invalide.');
        }
        if (isset($this->providers[$code])) {
            throw new InvalidArgumentException("Le fournisseur de paiement [{$code}] est déjà enregistré.");
        }
        $this->providers[$code] = $provider;
    }

    public function get(string $code): ?PaymentProviderPort
    {
        return $this->providers[$code] ?? null;
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    public function available(): array
    {
        $providers = [];
        foreach ($this->providers as $code => $provider) {
            $providers[] = ['code' => $code, 'name' => $provider->name()];
        }

        return $providers;
    }
}
