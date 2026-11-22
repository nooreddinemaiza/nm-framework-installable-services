<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Inventory;

use Core\Exceptions\Http\UnprocessableEntityException;
use Core\Support\Data\Data;

final readonly class InventoryConfig
{
    private const array ALLOWED_MODES = ['snapshot_adjust', 'freeze', 'ignore'];
    private const array ALLOWED_TRANSACTION_MODES = ['join', 'own_only'];

    public function __construct(
        public bool $enabled,
        public bool $multiWarehouse,
        public int $quantityScale,
        public bool $allowBackdating,
        public string $concurrentMovementMode,
        public string $transactionMode,
    ) {
        $errors = Data::create([
            'quantity_scale' => $quantityScale,
            'concurrent_movement_mode' => $concurrentMovementMode,
            'transaction_mode' => $transactionMode,
        ])->validate([
            'quantity_scale' => 'required|integer|min:0|max:6',
            'concurrent_movement_mode' => 'required|in:' . implode(',', self::ALLOWED_MODES),
            'transaction_mode' => 'required|in:' . implode(',', self::ALLOWED_TRANSACTION_MODES),
        ]);
        if ($errors !== []) {
            throw new UnprocessableEntityException($errors);
        }
        if ($quantityScale < 0 || $quantityScale > 6) {
            throw new UnprocessableEntityException(['quantity_scale' => ['La précision des quantités doit être comprise entre 0 et 6.']]);
        }

        if (!in_array($concurrentMovementMode, self::ALLOWED_MODES, true)) {
            throw new UnprocessableEntityException(['concurrent_movement_mode' => ['Le mode de mouvement concurrent est invalide.']]);
        }

        if (!in_array($transactionMode, self::ALLOWED_TRANSACTION_MODES, true)) {
            throw new UnprocessableEntityException(['transaction_mode' => ['Le mode de transaction est invalide.']]);
        }
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function from(array $values): self
    {
        $allowedKeys = [
            'enabled',
            'multi_warehouse',
            'quantity_scale',
            'allow_backdating',
            'concurrent_movement_mode',
            'transaction_mode',
        ];
        $unknownKeys = array_diff(array_keys($values), $allowedKeys);

        if ($unknownKeys !== []) {
            throw new UnprocessableEntityException([
                'configuration' => ['Clés de configuration Inventory inconnues : ' . implode(', ', $unknownKeys)],
            ]);
        }

        $errors = Data::create($values)->validate([
            'enabled' => 'required|boolean',
            'multi_warehouse' => 'required|boolean',
            'quantity_scale' => 'required|integer|min:0|max:6',
            'allow_backdating' => 'required|boolean',
            'concurrent_movement_mode' => 'required|in:' . implode(',', self::ALLOWED_MODES),
            'transaction_mode' => 'required|in:' . implode(',', self::ALLOWED_TRANSACTION_MODES),
        ]);
        if ($errors !== []) {
            throw new UnprocessableEntityException($errors);
        }

        return new self(
            enabled: self::boolean($values, 'enabled'),
            multiWarehouse: self::boolean($values, 'multi_warehouse'),
            quantityScale: self::integer($values, 'quantity_scale'),
            allowBackdating: self::boolean($values, 'allow_backdating'),
            concurrentMovementMode: self::string($values, 'concurrent_movement_mode'),
            transactionMode: self::string($values, 'transaction_mode'),
        );
    }

    /** @param array<string, mixed> $values */
    private static function boolean(array $values, string $key): bool
    {
        if (!isset($values[$key]) || !is_bool($values[$key])) {
            throw new UnprocessableEntityException([$key => ['La valeur doit être booléenne.']]);
        }

        return $values[$key];
    }

    /** @param array<string, mixed> $values */
    private static function integer(array $values, string $key): int
    {
        if (!isset($values[$key]) || !is_int($values[$key])) {
            throw new UnprocessableEntityException([$key => ['La valeur doit être un entier.']]);
        }

        return $values[$key];
    }

    /** @param array<string, mixed> $values */
    private static function string(array $values, string $key): string
    {
        if (!isset($values[$key]) || !is_string($values[$key])) {
            throw new UnprocessableEntityException([$key => ['La valeur doit être une chaîne de caractères.']]);
        }

        return $values[$key];
    }
}
