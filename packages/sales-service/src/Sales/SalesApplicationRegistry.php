<?php

declare(strict_types=1);

namespace NM\Services\SalesService\Sales;

use App\Models\User;
use Core\Database\Connection;
use InvalidArgumentException;
use RuntimeException;

final class SalesApplicationRegistry
{
    private const PROFILES = ['generic', 'retail', 'restaurant', 'service'];

    /** @var array<string, list<string>> */
    private const MODULE_DEPENDENCIES = [
        'catalog' => [],
        'availability' => [],
        'pricing' => [],
        'promotions' => ['pricing'],
        'customers' => [],
        'cart' => [],
        'checkout' => ['cart', 'catalog', 'availability', 'pricing', 'orders'],
        'quotes' => ['pricing'],
        'orders' => [],
        'fulfillment' => ['orders'],
        'invoicing' => ['orders'],
        'payments' => ['orders'],
        'returns' => ['orders'],
        'notifications' => [],
        'reporting' => ['orders'],
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly SalesServiceState $serviceState,
    ) {
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function register(
        User $actor,
        string $code,
        string $name,
        string $profile = 'generic',
        string $currency = 'MAD',
        string $locale = 'fr',
        ?int $tenantId = null,
        array $settings = [],
    ): int {
        self::assertAdministrator($actor);
        $this->serviceState->assertEnabled();
        $code = strtolower(trim($code));
        $name = trim($name);
        $currency = strtoupper(trim($currency));
        $locale = trim($locale);

        if (!preg_match('/^[a-z][a-z0-9._-]{0,79}$/D', $code)) {
            throw new InvalidArgumentException('Application code must be 1-80 lowercase letters, digits, dots, underscores or hyphens.');
        }
        if (preg_match('/^.{1,160}$/us', $name) !== 1) {
            throw new InvalidArgumentException('Application name must contain 1-160 characters.');
        }
        if (!in_array($profile, self::PROFILES, true)) {
            throw new InvalidArgumentException('Unknown sales business profile.');
        }
        if (!preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new InvalidArgumentException('Currency must be an uppercase three-letter code.');
        }
        if (!preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/D', $locale)) {
            throw new InvalidArgumentException('Locale must use a supported language or language-region code.');
        }
        if ($tenantId !== null && $tenantId < 1) {
            throw new InvalidArgumentException('Tenant identifier must be positive.');
        }
        $settingsJson = self::encodePublicConfiguration($settings);
        $now = gmdate('Y-m-d H:i:s');
        $this->connection->insert(
            'INSERT INTO sales_applications (tenant_id, code, name, profile, status, currency, locale, settings_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $tenantId,
                $code,
                $name,
                $profile,
                'active',
                $currency,
                $locale,
                $settingsJson,
                $now,
                $now,
            ]
        );

        return (int) $this->connection->lastInsertId();
    }

    public function administrativeContext(User $actor, int $applicationId): SalesContext
    {
        self::assertAdministrator($actor);
        $application = $this->findById($actor, $applicationId);
        if ($application === null || $application['status'] !== 'active') {
            throw new RuntimeException('Sales application is unavailable.');
        }

        return new SalesContext(
            applicationId: $applicationId,
            tenantId: $application['tenant_id'],
            scopes: ['*'],
            actorReference: (string) $actor->getAuthIdentifier(),
        );
    }

    /**
     * Builds a restricted context for a server-configured public consumer.
     * Callers must never pass a code supplied by the request.
     *
     * @param list<string> $scopes
     */
    public function publicConsumerContext(string $code, array $scopes): SalesContext
    {
        self::assertApplicationCode($code);
        $this->serviceState->assertEnabled();
        $application = $this->connection->selectOne(
            'SELECT id, tenant_id FROM sales_applications WHERE code = ? AND status = ?',
            [$code, 'active']
        );
        if ($application === null) {
            throw new SalesConsumerUnavailableException('The configured Sales consumer is unavailable.');
        }

        return new SalesContext(
            applicationId: (int) $application['id'],
            tenantId: $application['tenant_id'] === null ? null : (int) $application['tenant_id'],
            scopes: $scopes,
        );
    }

    public function setCurrency(User $actor, int $applicationId, string $currency): void
    {
        self::assertAdministrator($actor);
        $this->serviceState->assertEnabled();
        $currency = strtoupper(trim($currency));
        if ($applicationId < 1 || !preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new InvalidArgumentException('Sales application currency is invalid.');
        }

        $changed = $this->connection->update(
            'UPDATE sales_applications SET currency = ?, updated_at = ? WHERE id = ? AND tenant_id IS NULL AND status = ?',
            [$currency, gmdate('Y-m-d H:i:s'), $applicationId, 'active']
        );
        if ($changed !== 1) {
            throw new RuntimeException('Sales application currency could not be updated.');
        }
    }

    /**
     * @return array{id: int, tenant_id: ?int, code: string, name: string, profile: string, status: string, currency: string, locale: string, settings_json: string}|null
     */
    public function findByCode(User $actor, string $code): ?array
    {
        self::assertAdministrator($actor);
        $this->serviceState->assertEnabled();
        $row = $this->connection->selectOne(
            'SELECT id, tenant_id, code, name, profile, status, currency, locale, settings_json FROM sales_applications WHERE code = ?',
            [$code]
        );

        if ($row === null) {
            return null;
        }

        $row['id'] = (int) $row['id'];
        $row['tenant_id'] = $row['tenant_id'] === null ? null : (int) $row['tenant_id'];
        return $row;
    }

    /**
     * @return array{id: int, tenant_id: ?int, code: string, name: string, profile: string, status: string, currency: string, locale: string, settings_json: string}|null
     */
    public function findById(User $actor, int $applicationId): ?array
    {
        self::assertAdministrator($actor);
        $this->serviceState->assertEnabled();
        $row = $this->connection->selectOne(
            'SELECT id, tenant_id, code, name, profile, status, currency, locale, settings_json FROM sales_applications WHERE id = ?',
            [$applicationId]
        );
        if ($row === null) {
            return null;
        }

        $row['id'] = (int) $row['id'];
        $row['tenant_id'] = $row['tenant_id'] === null ? null : (int) $row['tenant_id'];
        return $row;
    }

    /**
     * @return array{id: int, tenant_id: ?int, code: string, name: string, profile: string, status: string, currency: string, locale: string, settings_json: string}
     */
    public function forContext(SalesContext $context): array
    {
        $this->assertActiveContext($context);
        $application = $this->connection->selectOne(
            'SELECT id, tenant_id, code, name, profile, status, currency, locale, settings_json FROM sales_applications WHERE id = ? AND status = ?',
            [$context->applicationId, 'active']
        );
        if ($application === null) {
            throw new RuntimeException('Sales application is unavailable.');
        }

        $application['id'] = (int) $application['id'];
        $application['tenant_id'] = $application['tenant_id'] === null ? null : (int) $application['tenant_id'];

        return $application;
    }

    /**
     * @param array<string, mixed> $configuration
     */
    public function enableModule(SalesContext $context, string $module, array $configuration = []): void
    {
        $this->assertActiveContext($context);
        $context->requireScope('applications:modules:manage');
        $dependencies = self::MODULE_DEPENDENCIES[$module] ?? null;
        if ($dependencies === null) {
            throw new InvalidArgumentException('Unknown sales module.');
        }

        foreach ($dependencies as $dependency) {
            if (!$this->isModuleEnabled($context, $dependency)) {
                throw new RuntimeException("Sales module [{$module}] requires [{$dependency}].");
            }
        }

        $configurationJson = self::encodePublicConfiguration($configuration);
        $now = gmdate('Y-m-d H:i:s');
        $existing = $this->connection->selectOne(
            'SELECT id FROM sales_application_modules WHERE application_id = ? AND module_key = ? AND tenant_id ' . ($context->tenantId === null ? 'IS NULL' : '= ?'),
            $context->tenantId === null
                ? [$context->applicationId, $module]
                : [$context->applicationId, $module, $context->tenantId]
        );
        if ($existing === null) {
            $this->connection->insert(
                'INSERT INTO sales_application_modules (application_id, tenant_id, module_key, version, status, config_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$context->applicationId, $context->tenantId, $module, '1.0.0', 'enabled', $configurationJson, $now, $now]
            );
            return;
        }

        $this->connection->update(
            'UPDATE sales_application_modules SET status = ?, config_json = ?, updated_at = ? WHERE application_id = ? AND module_key = ? AND tenant_id ' . ($context->tenantId === null ? 'IS NULL' : '= ?'),
            $context->tenantId === null
                ? ['enabled', $configurationJson, $now, $context->applicationId, $module]
                : ['enabled', $configurationJson, $now, $context->applicationId, $module, $context->tenantId]
        );
    }

    public function disableModule(SalesContext $context, string $module): void
    {
        $this->assertActiveContext($context);
        $context->requireScope('applications:modules:manage');
        if (!isset(self::MODULE_DEPENDENCIES[$module])) {
            throw new InvalidArgumentException('Unknown sales module.');
        }

        $enabledDependents = [];
        foreach (self::MODULE_DEPENDENCIES as $candidate => $dependencies) {
            if (in_array($module, $dependencies, true) && $this->isModuleEnabled($context, $candidate)) {
                $enabledDependents[] = $candidate;
            }
        }
        if ($enabledDependents !== []) {
            throw new RuntimeException(
                'Disable dependent sales modules first: ' . implode(', ', $enabledDependents)
            );
        }

        $this->connection->update(
            'UPDATE sales_application_modules SET status = ?, updated_at = ? WHERE application_id = ? AND module_key = ? AND tenant_id ' . ($context->tenantId === null ? 'IS NULL' : '= ?'),
            $context->tenantId === null
                ? ['disabled', gmdate('Y-m-d H:i:s'), $context->applicationId, $module]
                : ['disabled', gmdate('Y-m-d H:i:s'), $context->applicationId, $module, $context->tenantId]
        );
    }

    public function isModuleEnabled(SalesContext $context, string $module): bool
    {
        $this->assertActiveContext($context);
        $row = $this->connection->selectOne(
            'SELECT status FROM sales_application_modules WHERE application_id = ? AND module_key = ? AND tenant_id ' . ($context->tenantId === null ? 'IS NULL' : '= ?'),
            $context->tenantId === null
                ? [$context->applicationId, $module]
                : [$context->applicationId, $module, $context->tenantId]
        );

        return $row !== null && $row['status'] === 'enabled';
    }

    /**
     * @return array<string, mixed>
     */
    public function moduleConfiguration(SalesContext $context, string $module): array
    {
        $this->assertActiveContext($context);
        $row = $this->connection->selectOne(
            'SELECT config_json FROM sales_application_modules WHERE application_id = ? AND module_key = ? AND status = ? AND tenant_id ' . ($context->tenantId === null ? 'IS NULL' : '= ?'),
            $context->tenantId === null
                ? [$context->applicationId, $module, 'enabled']
                : [$context->applicationId, $module, 'enabled', $context->tenantId]
        );
        if ($row === null) {
            throw new RuntimeException("Sales module [{$module}] is not enabled for this application.");
        }

        $configuration = json_decode((string) $row['config_json'], true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($configuration)) {
            throw new RuntimeException("Sales module [{$module}] configuration is invalid.");
        }

        return $configuration;
    }

    public function assertActiveContext(SalesContext $context): void
    {
        $this->serviceState->assertEnabled();
        $application = $this->connection->selectOne(
            'SELECT tenant_id, status FROM sales_applications WHERE id = ?',
            [$context->applicationId]
        );
        if ($application === null || $application['status'] !== 'active') {
            throw new RuntimeException('Sales application is unavailable.');
        }
        if (($application['tenant_id'] === null ? null : (int) $application['tenant_id']) !== $context->tenantId) {
            throw new SalesAuthorizationException('Sales application context is invalid.');
        }
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private static function encodePublicConfiguration(array $configuration): string
    {
        $inspect = static function (array $values, int $depth = 0) use (&$inspect): void {
            if ($depth > 16) {
                throw new InvalidArgumentException('Sales configuration nesting is too deep.');
            }
            foreach ($values as $key => $value) {
                if (is_string($key) && preg_match('/(?:secret|password|token|api[_-]?key|private[_-]?key)/i', $key)) {
                    throw new InvalidArgumentException('Secrets must not be stored in public Sales configuration.');
                }
                if (is_array($value)) {
                    $inspect($value, $depth + 1);
                }
            }
        };
        $inspect($configuration);

        $encoded = json_encode($configuration, JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 65_535) {
            throw new InvalidArgumentException('Sales configuration exceeds the maximum size.');
        }

        return $encoded;
    }

    private static function assertAdministrator(User $actor): void
    {
        if (!$actor->is_active() || !$actor->isSuper()) {
            throw new SalesAuthorizationException('Only an active super administrator can manage Sales applications.');
        }
    }

    private static function assertApplicationCode(string $code): void
    {
        if (!preg_match('/^[a-z][a-z0-9._-]{0,79}$/D', $code)) {
            throw new InvalidArgumentException('Sales application code is invalid.');
        }
    }
}
