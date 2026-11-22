<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Inventory\Discount\Services;

use NM\Services\InventoryService\Inventory\Discount\DTO\DiscountResult;
use NM\Services\InventoryService\Inventory\Discount\Models\Discount;
use Core\Support\Date;
use Core\Exceptions\Http\UnprocessableEntityException;
use Core\Support\Data\Data;
use DateTimeImmutable;
use DateTimeZone;

final class DiscountCalculator
{
    /**
     * @param list<Discount> $discounts Already resolved and ordered discounts.
     */
    public function calculate(
        string $price,
        string $currency,
        array $discounts,
        ?DateTimeImmutable $calculatedAt = null,
    ): DiscountResult {
        $errors = Data::create(['price' => $price, 'currency' => $currency])->validate([
            'price' => 'required|numeric|min:0',
            'currency' => 'required|string|regex:/^[A-Z]{3}$/',
        ]);
        if ($errors !== []) {
            throw new UnprocessableEntityException($errors);
        }
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new UnprocessableEntityException(['currency' => ['La devise doit être un code de trois lettres majuscules.']]);
        }

        [$originalDigits, $scale] = self::toScaledPrice($price);
        $original = $originalDigits;
        $current = $originalDigits;
        $applied = [];

        foreach ($discounts as $discount) {
            if ($discount->discountType === 'fixed' && $discount->currency !== $currency) {
                throw new UnprocessableEntityException(['currency' => ['La devise de la réduction fixe doit correspondre à celle du prix.']]);
            }
            $before = $current;
            $valueMinor = self::toMinorValue($discount->value);
            if ($valueMinor < 1 || ($discount->discountType === 'percentage' && $valueMinor > 10_000)) {
                throw new UnprocessableEntityException(['value' => ['La valeur de la réduction est hors limites.']]);
            }
            if ($discount->discountType === 'percentage') {
                $current = self::multiplySmall($current, 10_000 - $valueMinor);
                $scale += 4;
            } elseif ($discount->discountType === 'fixed') {
                $fixed = self::normalizeUnsigned((string) $valueMinor . str_repeat('0', $scale - 2));
                $current = self::compareUnsigned($fixed, $current) >= 0
                    ? '0'
                    : self::subtractUnsigned($current, $fixed);
            } else {
                throw new UnprocessableEntityException([
                    'discount_type' => ['Le type de réduction n’est pas pris en charge.'],
                ]);
            }

            $beforeMinor = self::roundToMinor($before, $scale - ($discount->discountType === 'percentage' ? 4 : 0));
            $afterMinor = self::roundToMinor($current, $scale);
            $applied[] = [
                'discount_id' => $discount->id,
                'target_type' => $discount->targetType,
                'target_id' => $discount->targetId,
                'discount_type' => $discount->discountType,
                'value' => $discount->value,
                'currency' => $discount->currency,
                'price_before' => self::formatMinor($beforeMinor),
                'price_after' => self::formatMinor($afterMinor),
                'amount' => self::formatMinor(self::subtractUnsigned($beforeMinor, $afterMinor)),
            ];
        }

        $originalMinor = self::roundToMinor($original, 4);
        $finalMinor = self::roundToMinor($current, $scale);
        $savingMinor = self::subtractUnsigned($originalMinor, $finalMinor);
        $at = ($calculatedAt ?? Date::now('UTC'))->setTimezone(new DateTimeZone('UTC'));

        return new DiscountResult(
            originalPrice: self::formatMinor($originalMinor),
            finalPrice: self::formatMinor($finalMinor),
            totalSaving: self::formatMinor($savingMinor),
            totalSavingPercentage: self::formatPercentage($savingMinor, $originalMinor),
            currency: $currency,
            calculatedAt: $at->format('Y-m-d\TH:i:s\Z'),
            appliedDiscounts: $applied,
        );
    }

    /** @return array{string, int} */
    private static function toScaledPrice(string $price): array
    {
        if (preg_match('/^(0|[1-9][0-9]{0,13})(?:\.([0-9]{1,4}))?$/D', $price, $matches) !== 1) {
            throw new UnprocessableEntityException([
                'price' => ['Le prix doit être positif ou nul avec au maximum quatre décimales.'],
            ]);
        }

        return [self::normalizeUnsigned($matches[1] . str_pad($matches[2] ?? '', 4, '0')), 4];
    }

    private static function toMinorValue(string $value): int
    {
        if (preg_match('/^(0|[1-9][0-9]{0,9})\.([0-9]{2})$/D', $value, $matches) !== 1) {
            throw new UnprocessableEntityException(['value' => ['La valeur de la réduction est invalide.']]);
        }

        return (int) $matches[1] * 100 + (int) $matches[2];
    }

    private static function roundToMinor(string $digits, int $scale): string
    {
        if ($scale < 2) {
            return self::normalizeUnsigned($digits . str_repeat('0', 2 - $scale));
        }

        $fractionDigits = $scale - 2;
        if ($fractionDigits === 0) {
            return self::normalizeUnsigned($digits);
        }
        $padded = str_pad($digits, $fractionDigits + 1, '0', STR_PAD_LEFT);
        $minor = self::normalizeUnsigned(substr($padded, 0, -$fractionDigits));
        if ((int) $padded[strlen($padded) - $fractionDigits] >= 5) {
            $minor = self::addOne($minor);
        }

        return $minor;
    }

    private static function formatMinor(string $minor): string
    {
        $minor = str_pad($minor, 3, '0', STR_PAD_LEFT);

        return self::normalizeUnsigned(substr($minor, 0, -2)) . '.' . substr($minor, -2);
    }

    private static function formatPercentage(string $saving, string $original): string
    {
        if ($original === '0' || $saving === '0') {
            return '0.00';
        }
        if (self::compareUnsigned($saving, $original) >= 0) {
            return '100.00';
        }

        $numerator = self::multiplySmall($saving, 10_000);
        $remainder = '0';
        $quotient = '';
        foreach (str_split($numerator) as $digit) {
            $remainder = self::normalizeUnsigned(($remainder === '0' ? '' : $remainder) . $digit);
            $quotientDigit = 0;
            while (self::compareUnsigned($remainder, $original) >= 0) {
                $remainder = self::subtractUnsigned($remainder, $original);
                ++$quotientDigit;
            }
            $quotient .= (string) $quotientDigit;
        }
        $hundredths = (int) self::normalizeUnsigned($quotient);
        if (self::compareUnsigned(self::multiplySmall($remainder, 2), $original) >= 0) {
            ++$hundredths;
        }
        if ($hundredths >= 10_000) {
            return '100.00';
        }

        return intdiv($hundredths, 100) . '.' . str_pad((string) ($hundredths % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function multiplySmall(string $value, int $factor): string
    {
        if ($factor === 0 || $value === '0') {
            return '0';
        }
        $carry = 0;
        $result = '';
        for ($index = strlen($value) - 1; $index >= 0; --$index) {
            $product = (int) $value[$index] * $factor + $carry;
            $result = (string) ($product % 10) . $result;
            $carry = intdiv($product, 10);
        }
        while ($carry > 0) {
            $result = (string) ($carry % 10) . $result;
            $carry = intdiv($carry, 10);
        }

        return self::normalizeUnsigned($result);
    }

    private static function compareUnsigned(string $left, string $right): int
    {
        $left = self::normalizeUnsigned($left);
        $right = self::normalizeUnsigned($right);
        if (strlen($left) !== strlen($right)) {
            return strlen($left) <=> strlen($right);
        }

        return strcmp($left, $right) <=> 0;
    }

    private static function subtractUnsigned(string $left, string $right): string
    {
        $left = str_pad(self::normalizeUnsigned($left), max(strlen($left), strlen($right)), '0', STR_PAD_LEFT);
        $right = str_pad(self::normalizeUnsigned($right), strlen($left), '0', STR_PAD_LEFT);
        $borrow = 0;
        $result = '';
        for ($index = strlen($left) - 1; $index >= 0; --$index) {
            $digit = (int) $left[$index] - (int) $right[$index] - $borrow;
            $borrow = $digit < 0 ? 1 : 0;
            if ($borrow) {
                $digit += 10;
            }
            $result = (string) $digit . $result;
        }

        return self::normalizeUnsigned($result);
    }

    private static function addOne(string $value): string
    {
        for ($index = strlen($value) - 1; $index >= 0; --$index) {
            if ($value[$index] !== '9') {
                $value[$index] = (string) ((int) $value[$index] + 1);

                return $value;
            }
            $value[$index] = '0';
        }

        return '1' . $value;
    }

    private static function normalizeUnsigned(string $value): string
    {
        $normalized = ltrim($value, '0');

        return $normalized === '' ? '0' : $normalized;
    }
}
