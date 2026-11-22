<?php

declare(strict_types=1);

namespace NM\Services\SocialAuth\Social;

use Core\Database\Connection;
use PDO;

final class SocialProviderSettingsStore
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<string, array{enabled?: bool, client_id?: string, redirect_uri?: string}>
     */
    public function overrides(): array
    {
        $keyColumn = $this->quotedKeyColumn();
        $rows = $this->connection->select(
            "SELECT {$keyColumn} AS setting_key, value FROM settings WHERE {$keyColumn} LIKE ?",
            ['social.provider.%']
        );
        $overrides = [];

        foreach ($rows as $row) {
            $key = (string) ($row['setting_key'] ?? '');
            if (preg_match('/^social\\.provider\\.([a-z0-9_-]+)\\.(enabled|client_id|redirect_uri)$/', $key, $matches) !== 1
                || !isset(SocialProviderCatalog::PROVIDERS[$matches[1]])) {
                continue;
            }

            $provider = $matches[1];
            $field = $matches[2];
            $overrides[$provider][$field] = $field === 'enabled'
                ? filter_var($row['value'] ?? false, FILTER_VALIDATE_BOOLEAN)
                : (string) ($row['value'] ?? '');
        }

        return $overrides;
    }

    /**
     * @param array<string, array{enabled: bool, client_id: string, redirect_uri: string}> $settings
     */
    public function save(array $settings): void
    {
        $this->connection->transaction(function () use ($settings): void {
            foreach ($settings as $provider => $values) {
                if (!isset(SocialProviderCatalog::PROVIDERS[$provider])) {
                    continue;
                }

                $this->write('social.provider.' . $provider . '.enabled', $values['enabled'] ? 'true' : 'false', 'boolean');
                $this->write('social.provider.' . $provider . '.client_id', $values['client_id'], 'string');
                $this->write('social.provider.' . $provider . '.redirect_uri', $values['redirect_uri'], 'string');
            }
        });
    }

    private function write(string $key, string $value, string $type): void
    {
        $keyColumn = $this->quotedKeyColumn();
        $existing = $this->connection->selectOne(
            "SELECT id FROM settings WHERE {$keyColumn} = ?",
            [$key]
        );
        $now = date('Y-m-d H:i:s');

        if ($existing === null) {
            $this->connection->insert(
                "INSERT INTO settings ({$keyColumn}, value, type, created_at, updated_at) VALUES (?, ?, ?, ?, ?)",
                [$key, $value, $type, $now, $now]
            );

            return;
        }

        $this->connection->update(
            "UPDATE settings SET value = ?, type = ?, updated_at = ? WHERE {$keyColumn} = ?",
            [$value, $type, $now, $key]
        );
    }

    private function quotedKeyColumn(): string
    {
        return $this->connection->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? '`key`'
            : '"key"';
    }
}
