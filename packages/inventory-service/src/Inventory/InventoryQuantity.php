<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Inventory;

use Core\Exceptions\Http\UnprocessableEntityException;
use Core\Exceptions\InternalServerException;
use Core\Support\Data\Data;

final class InventoryQuantity
{
    private string $value;

    public function __construct(string $value)
    {
        $this->value = self::normalize($value);
    }

    public static function zero(): self
    {
        return new self('0');
    }

    public static function from(string $value): self
    {
        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function isZero(): bool
    {
        return self::compare($this->value, '0') === 0;
    }

    public function add(self $other): self
    {
        return new self(self::applyBinaryOperation($this->value, $other->value, 'add'));
    }

    public function subtract(self $other): self
    {
        return new self(self::applyBinaryOperation($this->value, $other->value, 'subtract'));
    }

    public function negate(): self
    {
        return new self(self::applyUnaryOperation($this->value, 'negate'));
    }

    public function isPositive(): bool
    {
        return self::compare($this->value, '0') > 0;
    }

    public function isNegative(): bool
    {
        return self::compare($this->value, '0') < 0;
    }

    public static function compare(string $left, string $right): int
    {
        $left = self::normalize($left);
        $right = self::normalize($right);

        if (function_exists('bccomp')) {
            return bccomp($left, $right, 6);
        }

        $leftFloat = (float) $left;
        $rightFloat = (float) $right;

        return $leftFloat <=> $rightFloat;
    }

    public static function signedFor(string $movementType, string $quantity): self
    {
        $normalized = self::normalize($quantity);

        return match ($movementType) {
            'receipt', 'transfer_in', 'initial', 'adjustment' => new self($normalized),
            'issue', 'transfer_out', 'reversal' => new self('-' . ltrim($normalized, '-')),
            default => throw new UnprocessableEntityException([
                'movement_type' => [sprintf('Le type de mouvement [%s] n’est pas pris en charge.', $movementType)],
            ]),
        };
    }

    private static function normalize(string $value): string
    {
        $trimmed = trim((string) $value);

        $errors = Data::create(['quantity' => $trimmed])->validate(['quantity' => 'required|numeric']);
        if ($errors !== []) {
            throw new UnprocessableEntityException($errors);
        }
        if (!preg_match('/^-?\d+(?:\.\d+)?$/', $trimmed)) {
            throw new UnprocessableEntityException([
                'quantity' => [sprintf('La quantité [%s] est invalide.', $trimmed)],
            ]);
        }

        $normalized = preg_replace('/^(-?)0+(\d)/', '$1$2', $trimmed) ?? $trimmed;
        $normalized = preg_replace('/\.(\d*?)0+$/', '.$1', $normalized) ?? $normalized;
        $normalized = preg_replace('/\.$/', '', $normalized) ?? $normalized;

        if ($normalized === '-0') {
            return '0';
        }

        if ($normalized === '') {
            return '0';
        }

        return $normalized;
    }

    private static function applyBinaryOperation(string $left, string $right, string $operation): string
    {
        $left = self::normalize($left);
        $right = self::normalize($right);

        if (function_exists('bcadd') && function_exists('bcsub')) {
            return match ($operation) {
                'add' => bcadd($left, $right, 6),
                'subtract' => bcsub($left, $right, 6),
                default => throw new InternalServerException("Unsupported decimal operation [{$operation}]."),
            };
        }

        $base = (float) $left + ((float) $right * ($operation === 'subtract' ? -1 : 1));

        return number_format($base, 6, '.', '');
    }

    private static function applyUnaryOperation(string $value, string $operation): string
    {
        $value = self::normalize($value);

        if (function_exists('bcmul')) {
            return match ($operation) {
                'negate' => bcmul($value, '-1', 6),
                default => throw new InternalServerException("Unsupported decimal operation [{$operation}]."),
            };
        }

        return number_format((float) $value * -1, 6, '.', '');
    }
}
