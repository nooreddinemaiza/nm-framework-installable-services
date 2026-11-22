<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Inventory\Discount\Validators;

use NM\Services\InventoryService\Inventory\Discount\DiscountTargetRegistry;
use Core\Exceptions\Http\UnprocessableEntityException;
use Core\Support\Data\Data;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class DiscountValidator
{
    /**
     * @param array<string, mixed> $data
     * @return array{target_type: string, target_id: int, discount_type: string, value: string, currency: ?string, starts_at: string, ends_at: string, is_active: bool}
     */
    public function validate(array $data, DiscountTargetRegistry $targets): array
    {
        $input = Data::create($data);
        $errors = $input->validate([
            'target_type' => 'required|string',
            'target_id' => 'required|integer|min:1',
            'discount_type' => 'required|in:percentage,fixed',
            'value' => 'required',
            'currency' => 'nullable|string',
            'starts_at' => 'required|string',
            'ends_at' => 'required|string',
            'is_active' => 'nullable|boolean',
        ]);
        if ($errors !== []) {
            throw new UnprocessableEntityException($errors);
        }

        $type = $input->get('target_type');
        $targetId = $input->get('target_id');
        if (is_string($targetId) && preg_match('/^[1-9][0-9]{0,17}$/D', $targetId) === 1) {
            $targetId = (int) $targetId;
        }
        if (!is_string($type) || !is_int($targetId) || $targetId < 1) {
            throw self::invalid('target_id', 'Une cible de réduction enregistrée est obligatoire.');
        }
        $target = $targets->get($type);
        if ($target === null || !$target->exists($targetId)) {
            throw self::invalid('target_id', 'La cible de réduction n’existe pas ou n’est pas autorisée.');
        }

        $discountType = $input->get('discount_type');
        if (!in_array($discountType, ['percentage', 'fixed'], true)) {
            throw self::invalid('discount_type', 'Le type de réduction doit être un pourcentage ou un montant fixe.');
        }
        $value = self::normalizeValue($input->get('value'));
        if ($discountType === 'percentage' && self::minorUnits($value) > 10_000) {
            throw self::invalid('value', 'Une réduction en pourcentage ne peut pas dépasser 100.');
        }

        $currency = $input->get('currency');
        if ($discountType === 'fixed') {
            if (!is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
                throw self::invalid('currency', 'Une réduction fixe exige une devise en trois lettres majuscules.');
            }
        } elseif ($currency !== null && $currency !== '') {
            throw self::invalid('currency', 'Une réduction en pourcentage ne peut pas avoir de devise.');
        } else {
            $currency = null;
        }

        $startsAt = self::utcDate($input->get('starts_at'), 'starts_at');
        $endsAt = self::utcDate($input->get('ends_at'), 'ends_at');
        if ($endsAt <= $startsAt) {
            throw self::invalid('ends_at', 'La date de fin doit être postérieure à la date de début.');
        }

        $active = $input->get('is_active', true);
        if (!is_bool($active) && !in_array($active, [0, 1, '0', '1'], true)) {
            throw self::invalid('is_active', 'Le statut de la réduction doit être une valeur booléenne.');
        }

        return [
            'target_type' => $type,
            'target_id' => $targetId,
            'discount_type' => $discountType,
            'value' => $value,
            'currency' => $currency,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'is_active' => (bool) $active,
        ];
    }

    private static function normalizeValue(mixed $value): string
    {
        if (!is_string($value) && !is_int($value)) {
            throw self::invalid('value', 'La valeur doit être un nombre décimal.');
        }
        $value = (string) $value;
        if (preg_match('/^(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?$/D', $value, $matches) !== 1) {
            throw self::invalid('value', 'La valeur doit être positive et comporter au maximum deux décimales.');
        }
        $minor = self::minorUnits($value);
        if ($minor < 1) {
            throw self::invalid('value', 'La valeur de la réduction doit être supérieure à zéro.');
        }

        return $matches[1] . '.' . str_pad($matches[2] ?? '', 2, '0');
    }

    private static function minorUnits(string $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    private static function utcDate(mixed $value, string $field): string
    {
        if (!is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})?$/D', $value) !== 1) {
            throw self::invalid($field, 'Une date et une heure valides sont obligatoires.');
        }
        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            throw self::invalid($field, 'La date et l’heure sont invalides.');
        }
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            throw self::invalid($field, 'La date et l’heure sont invalides.');
        }

        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function invalid(string $field, string $message): UnprocessableEntityException
    {
        return new UnprocessableEntityException([$field => [$message]], $message);
    }
}
