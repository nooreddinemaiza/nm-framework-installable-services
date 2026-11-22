<?php

declare(strict_types=1);

namespace NM\Services\SalesService\Sales;

use Core\Database\Connection;
use InvalidArgumentException;
use RuntimeException;

final class SalesApiKeyService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SalesServiceState $serviceState,
    ) {
    }

    /**
     * @param list<string> $scopes
     */
    public function issue(
        SalesContext $context,
        string $name,
        array $scopes,
        ?string $expiresAt = null,
    ): IssuedSalesApiKey {
        $context->requireScope('applications:keys:manage');
        $this->assertActiveContext($context);
        $name = trim($name);
        if (preg_match('/^.{1,120}$/us', $name) !== 1) {
            throw new InvalidArgumentException('API key name must contain 1-120 characters.');
        }
        if ($scopes === [] || count(array_filter(
            $scopes,
            static fn (mixed $scope): bool => is_string($scope)
                && ($scope === '*' || preg_match('/^[a-z][a-z0-9:_-]{1,99}$/D', $scope) === 1)
        )) !== count($scopes)) {
            throw new InvalidArgumentException('API key scopes are invalid.');
        }
        foreach ($scopes as $scope) {
            if (!$context->allows($scope)) {
                throw new SalesAuthorizationException('An API key cannot grant scopes the issuer does not hold.');
            }
        }
        if ($expiresAt !== null) {
            $expiry = new \DateTimeImmutable($expiresAt, new \DateTimeZone('UTC'));
            if ($expiry <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
                throw new InvalidArgumentException('API key expiration must be in the future.');
            }
            $expiresAt = $expiry->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } else {
            $expiresAt = gmdate('Y-m-d H:i:s', time() + 7_776_000);
        }

        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hash = hash('sha256', $secret);
        $now = gmdate('Y-m-d H:i:s');
        $this->connection->insert(
            'INSERT INTO sales_api_keys (application_id, tenant_id, name, key_hash, scopes_json, expires_at, revoked_at, last_used_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?)',
            [
                $context->applicationId,
                $context->tenantId,
                $name,
                $hash,
                json_encode(array_values(array_unique($scopes)), JSON_THROW_ON_ERROR),
                $expiresAt,
                $now,
                $now,
            ]
        );

        return new IssuedSalesApiKey(
            id: (int) $this->connection->lastInsertId(),
            name: $name,
            secret: $secret,
            scopes: array_values(array_unique($scopes)),
            expiresAt: $expiresAt,
        );
    }

    public function authenticate(string $secret, string $requiredScope): SalesContext
    {
        $this->serviceState->assertEnabled();
        if ($secret === '' || strlen($secret) > 128) {
            throw new SalesAuthorizationException('Invalid sales API key.');
        }

        $hash = hash('sha256', $secret);
        $row = $this->connection->selectOne(
            'SELECT id, application_id, tenant_id, key_hash, scopes_json, expires_at FROM sales_api_keys WHERE key_hash = ? AND revoked_at IS NULL',
            [$hash]
        );
        if ($row === null || !hash_equals((string) $row['key_hash'], $hash)) {
            throw new SalesAuthorizationException('Invalid sales API key.');
        }
        if ($row['expires_at'] !== null && new \DateTimeImmutable((string) $row['expires_at'], new \DateTimeZone('UTC')) <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
            throw new SalesAuthorizationException('Expired sales API key.');
        }

        $scopes = json_decode((string) $row['scopes_json'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($scopes) || !array_is_list($scopes) || !in_array('*', $scopes, true) && !in_array($requiredScope, $scopes, true)) {
            throw new SalesAuthorizationException('Sales API key does not grant the required scope.');
        }

        $application = $this->connection->selectOne(
            'SELECT tenant_id, status FROM sales_applications WHERE id = ?',
            [(int) $row['application_id']]
        );
        if ($application === null || $application['status'] !== 'active') {
            throw new RuntimeException('Sales application is unavailable.');
        }

        if (($application['tenant_id'] === null ? null : (int) $application['tenant_id'])
            !== ($row['tenant_id'] === null ? null : (int) $row['tenant_id'])) {
            throw new SalesAuthorizationException('Sales API key context is no longer valid.');
        }

        if (count(array_filter(
            $scopes,
            static fn (mixed $scope): bool => is_string($scope)
                && ($scope === '*' || preg_match('/^[a-z][a-z0-9:_-]{1,99}$/D', $scope) === 1)
        )) !== count($scopes)) {
            throw new SalesAuthorizationException('Sales API key scopes are invalid.');
        }

        $now = gmdate('Y-m-d H:i:s');
        $this->connection->update(
            'UPDATE sales_api_keys SET last_used_at = ?, updated_at = ? WHERE id = ?',
            [$now, $now, (int) $row['id']]
        );

        return new SalesContext(
            applicationId: (int) $row['application_id'],
            tenantId: $application['tenant_id'] === null ? null : (int) $application['tenant_id'],
            scopes: $scopes,
        );
    }

    public function revoke(SalesContext $context, int $keyId): void
    {
        $context->requireScope('applications:keys:manage');
        $this->assertActiveContext($context);
        if ($keyId < 1) {
            throw new InvalidArgumentException('API key identifier must be positive.');
        }

        $this->connection->update(
            'UPDATE sales_api_keys SET revoked_at = ?, updated_at = ? WHERE id = ? AND application_id = ? AND revoked_at IS NULL',
            [gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s'), $keyId, $context->applicationId]
        );
    }

    private function assertActiveContext(SalesContext $context): void
    {
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
}
