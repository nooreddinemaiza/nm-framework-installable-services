<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Inventory;

use Core\Database\Connection;
use Core\Exceptions\Http\UnprocessableEntityException;
use Core\Support\Data\Data;
use InvalidArgumentException;

final class InventoryConfigurationStore
{
    private const array CONFIGURATION_SETTINGS = [
        'multi_warehouse' => ['key' => 'inventory.multi_warehouse', 'type' => 'boolean'],
        'quantity_scale' => ['key' => 'inventory.quantity_scale', 'type' => 'integer'],
        'allow_backdating' => ['key' => 'inventory.allow_backdating', 'type' => 'boolean'],
        'concurrent_movement_mode' => ['key' => 'inventory.concurrent_movement_mode', 'type' => 'string'],
        'transaction_mode' => ['key' => 'inventory.transaction_mode', 'type' => 'string'],
    ];

    private const array PREFERENCE_SETTINGS = [
        'simple_dashboard_enabled' => ['key' => 'inventory.simple_dashboard.enabled', 'type' => 'boolean'],
        'default_min_qty' => ['key' => 'inventory.default_min_qty', 'type' => 'decimal'],
        'default_movement_quantity' => ['key' => 'inventory.default_movement_quantity', 'type' => 'decimal'],
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return array<string, bool|int|string> */
    public function runtimeOverrides(): array
    {
        $definitions = self::CONFIGURATION_SETTINGS;
        $stored = $this->load(array_column($definitions, 'key'));
        $overrides = [];

        foreach ($definitions as $name => $definition) {
            $key = $definition['key'];
            if (!array_key_exists($key, $stored)) {
                continue;
            }

            $overrides[$name] = match ($definition['type']) {
                'boolean' => $this->parseBoolean($key, $stored[$key]),
                'integer' => $this->parseInteger($key, $stored[$key]),
                default => $stored[$key],
            };
        }

        return $overrides;
    }

    /**
     * @return array{
     *     multi_warehouse: bool,
     *     quantity_scale: int,
     *     allow_backdating: bool,
     *     concurrent_movement_mode: string,
     *     transaction_mode: string,
     *     simple_dashboard_enabled: bool,
     *     default_min_qty: string,
     *     default_movement_quantity: string
     * }
     */
    public function viewValues(InventoryConfig $config): array
    {
        $definitions = self::PREFERENCE_SETTINGS;
        $stored = $this->load(array_column($definitions, 'key'));

        return [
            'multi_warehouse' => $config->multiWarehouse,
            'quantity_scale' => $config->quantityScale,
            'allow_backdating' => $config->allowBackdating,
            'concurrent_movement_mode' => $config->concurrentMovementMode,
            'transaction_mode' => $config->transactionMode,
            'simple_dashboard_enabled' => $this->preferenceBoolean(
                $stored,
                self::PREFERENCE_SETTINGS['simple_dashboard_enabled']['key'],
                true
            ),
            'default_min_qty' => $this->preferenceQuantity(
                $stored,
                self::PREFERENCE_SETTINGS['default_min_qty']['key'],
                '5',
                allowZero: true
            ),
            'default_movement_quantity' => $this->preferenceQuantity(
                $stored,
                self::PREFERENCE_SETTINGS['default_movement_quantity']['key'],
                '1',
                allowZero: false
            ),
        ];
    }

    /** @param array<string, mixed> $input */
    public function save(array $input, InventoryConfig $current): void
    {
        $allowedFields = [
            'multi_warehouse',
            'quantity_scale',
            'allow_backdating',
            'concurrent_movement_mode',
            'transaction_mode',
            'simple_dashboard_enabled',
            'default_min_qty',
            'default_movement_quantity',
        ];
        $unknownFields = array_diff(array_keys($input), $allowedFields);
        if ($unknownFields !== []) {
            throw new UnprocessableEntityException([
                'configuration' => ['Champs de configuration Inventory inconnus : ' . implode(', ', $unknownFields)],
            ]);
        }

        $errors = Data::create($input)->validate([
            'multi_warehouse' => 'required|in:0,1',
            'quantity_scale' => 'required|integer|min:0|max:6',
            'allow_backdating' => 'required|in:0,1',
            'concurrent_movement_mode' => 'required|in:snapshot_adjust,freeze,ignore',
            'transaction_mode' => 'required|in:join,own_only',
            'simple_dashboard_enabled' => 'required|in:0,1',
            'default_min_qty' => 'required|numeric|min:0',
            'default_movement_quantity' => 'required|numeric|positive',
        ]);
        if ($errors !== []) {
            throw new UnprocessableEntityException($errors);
        }

        $quantityScale = filter_var($input['quantity_scale'], FILTER_VALIDATE_INT);
        if ($quantityScale === false) {
            throw new UnprocessableEntityException([
                'quantity_scale' => ['La précision des quantités doit être un entier.'],
            ]);
        }

        InventoryConfig::from([
            'enabled' => $current->enabled,
            'multi_warehouse' => $input['multi_warehouse'] === '1',
            'quantity_scale' => $quantityScale,
            'allow_backdating' => $input['allow_backdating'] === '1',
            'concurrent_movement_mode' => $input['concurrent_movement_mode'],
            'transaction_mode' => $input['transaction_mode'],
        ]);

        $minimumQuantity = $this->validatedQuantity('default_min_qty', $input['default_min_qty'], allowZero: true);
        $movementQuantity = $this->validatedQuantity('default_movement_quantity', $input['default_movement_quantity']);

        $values = [
            self::CONFIGURATION_SETTINGS['multi_warehouse']['key'] => [$input['multi_warehouse'] === '1' ? 'true' : 'false', 'boolean'],
            self::CONFIGURATION_SETTINGS['quantity_scale']['key'] => [(string) $quantityScale, 'integer'],
            self::CONFIGURATION_SETTINGS['allow_backdating']['key'] => [$input['allow_backdating'] === '1' ? 'true' : 'false', 'boolean'],
            self::CONFIGURATION_SETTINGS['concurrent_movement_mode']['key'] => [$input['concurrent_movement_mode'], 'string'],
            self::CONFIGURATION_SETTINGS['transaction_mode']['key'] => [$input['transaction_mode'], 'string'],
            self::PREFERENCE_SETTINGS['simple_dashboard_enabled']['key'] => [$input['simple_dashboard_enabled'] === '1' ? 'true' : 'false', 'boolean'],
            self::PREFERENCE_SETTINGS['default_min_qty']['key'] => [$minimumQuantity, 'decimal'],
            self::PREFERENCE_SETTINGS['default_movement_quantity']['key'] => [$movementQuantity, 'decimal'],
        ];

        $keyColumn = $this->quotedKeyColumn();
        $this->connection->transaction(function () use ($values, $keyColumn): void {
            $now = date('Y-m-d H:i:s');
            foreach ($values as $key => [$value, $type]) {
                $existing = $this->connection->selectOne(
                    "SELECT id FROM settings WHERE {$keyColumn} = ?",
                    [$key]
                );
                if ($existing === null) {
                    $this->connection->insert(
                        "INSERT INTO settings ({$keyColumn}, value, type, created_at, updated_at) VALUES (?, ?, ?, ?, ?)",
                        [$key, $value, $type, $now, $now]
                    );
                    continue;
                }

                $this->connection->update(
                    "UPDATE settings SET value = ?, type = ?, updated_at = ? WHERE {$keyColumn} = ?",
                    [$value, $type, $now, $key]
                );
            }
        });
    }

    /** @param list<string> $keys
     *  @return array<string, string>
     */
    private function load(array $keys): array
    {
        $keyColumn = $this->quotedKeyColumn();
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));
        $rows = $this->connection->select(
            "SELECT {$keyColumn} AS setting_key, value FROM settings WHERE {$keyColumn} IN ({$placeholders})",
            $keys
        );
        $values = [];
        foreach ($rows as $row) {
            $values[(string) $row['setting_key']] = (string) $row['value'];
        }

        return $values;
    }

    /** @param array<string, string> $stored */
    private function preferenceBoolean(array $stored, string $key, bool $default): bool
    {
        return array_key_exists($key, $stored) ? $this->parseBoolean($key, $stored[$key]) : $default;
    }

    /** @param array<string, string> $stored */
    private function preferenceQuantity(array $stored, string $key, string $default, bool $allowZero): string
    {
        $quantity = array_key_exists($key, $stored)
            ? $this->validatedQuantity($key, $stored[$key], $allowZero)
            : $default;

        return $quantity;
    }

    private function validatedQuantity(string $field, mixed $value, bool $allowZero = false): string
    {
        try {
            $quantity = InventoryQuantity::from((string) $value);
        } catch (UnprocessableEntityException $exception) {
            throw new UnprocessableEntityException([$field => [$exception->getMessage()]]);
        }

        if ($quantity->isNegative() || (!$allowZero && !$quantity->isPositive())) {
            throw new UnprocessableEntityException([
                $field => [$allowZero ? 'La quantité ne peut pas être négative.' : 'La quantité doit être supérieure à zéro.'],
            ]);
        }

        return $quantity->toString();
    }

    private function parseBoolean(string $key, string $value): bool
    {
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            throw new InvalidArgumentException(sprintf('Le paramètre Inventory [%s] doit être booléen.', $key));
        }

        return $parsed;
    }

    private function parseInteger(string $key, string $value): int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        if ($parsed === false) {
            throw new InvalidArgumentException(sprintf('Le paramètre Inventory [%s] doit être un entier.', $key));
        }

        return $parsed;
    }

    private function quotedKeyColumn(): string
    {
        return $this->connection->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? '`key`'
            : '"key"';
    }
}
