<?php

declare(strict_types=1);

namespace NM\Services\Quotes\Quotes;

use App\Models\Setting;
use Core\Database\Connection;
use Core\Exceptions\Http\UnprocessableEntityException;
use RuntimeException;

final class QuoteConfigurationStore
{
    public function __construct(private readonly ?Connection $connection = null)
    {
    }
    private const DEFAULTS = [
        'public_requests_enabled' => true,
        'product_requests_enabled' => true,
        'category_requests_enabled' => true,
        'brand_requests_enabled' => true,
        'allow_anonymous' => true,
        'require_phone' => false,
        'default_validity_days' => 30,
        'default_currency' => 'MAD',
        'max_lines' => 100,
        'max_quantity_per_line' => 1000,
        'max_search_results' => 20,
        'csv_enabled' => true,
        'pdf_enabled' => true,
        'commercial_terms' => '',
    ];

    /** @return array<string, bool|int|string> */
    public function values(): array
    {
        $values = [];
        foreach (self::DEFAULTS as $name => $default) {
            $setting = Setting::query()->where('key', '=', 'quotes.' . $name)->first();
            $values[$name] = $setting instanceof Setting
                ? $this->decode((string) $setting->getAttribute('value'), $default)
                : $default;
        }

        return $values;
    }

    /** @param array<string, mixed> $input */
    public function save(array $input): void
    {
        $allowed = array_keys(self::DEFAULTS);
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new UnprocessableEntityException(['configuration' => ['Configuration des devis inconnue.']]);
        }

        $boolFields = [
            'public_requests_enabled', 'product_requests_enabled', 'category_requests_enabled',
            'brand_requests_enabled', 'allow_anonymous', 'require_phone', 'csv_enabled', 'pdf_enabled',
        ];
        foreach ($boolFields as $field) {
            if (!isset($input[$field]) || !in_array((string) $input[$field], ['0', '1'], true)) {
                throw new UnprocessableEntityException([$field => ['Choisissez une valeur valide.']]);
            }
        }

        $validity = $this->integer($input, 'default_validity_days', 1, 365);
        $maxLines = $this->integer($input, 'max_lines', 1, 500);
        $maxQuantity = $this->integer($input, 'max_quantity_per_line', 1, 1000000);
        $maxSearch = $this->integer($input, 'max_search_results', 1, 100);
        $currency = strtoupper(trim((string) ($input['default_currency'] ?? '')));
        if (!preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new UnprocessableEntityException(['default_currency' => ['La devise doit contenir exactement trois lettres.']]);
        }
        $terms = trim((string) ($input['commercial_terms'] ?? ''));
        if (strlen($terms) > 5000) {
            throw new UnprocessableEntityException(['commercial_terms' => ['Les conditions sont limitées à 5000 caractères.']]);
        }

        $values = [
            'default_validity_days' => [(string) $validity, 'integer'],
            'max_lines' => [(string) $maxLines, 'integer'],
            'max_quantity_per_line' => [(string) $maxQuantity, 'integer'],
            'max_search_results' => [(string) $maxSearch, 'integer'],
            'default_currency' => [$currency, 'string'],
            'commercial_terms' => [$terms, 'string'],
        ];
        foreach ($boolFields as $field) {
            $values[$field] = [$input[$field] === '1' ? 'true' : 'false', 'boolean'];
        }

        foreach ($values as $name => [$value, $type]) {
            $key = 'quotes.' . $name;
            $setting = Setting::query()->where('key', '=', $key)->first();
            if ($setting instanceof Setting) {
                $setting->update(['value' => $value, 'type' => $type]);
            } else {
                Setting::create(['key' => $key, 'value' => $value, 'type' => $type]);
            }
        }
    }

    private function integer(array $input, string $name, int $minimum, int $maximum): int
    {
        $value = filter_var($input[$name] ?? null, FILTER_VALIDATE_INT);
        if ($value === false || $value < $minimum || $value > $maximum) {
            throw new UnprocessableEntityException([$name => [sprintf('Choisissez un entier entre %d et %d.', $minimum, $maximum)]]);
        }
        return $value;
    }

    private function decode(string $value, bool|int|string $default): bool|int|string
    {
        if (is_bool($default)) {
            $decoded = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($decoded === null) {
                throw new RuntimeException('La configuration booléenne des devis est invalide.');
            }
            return $decoded;
        }
        if (is_int($default)) {
            $decoded = filter_var($value, FILTER_VALIDATE_INT);
            if ($decoded === false) {
                throw new RuntimeException('La configuration entière des devis est invalide.');
            }
            return $decoded;
        }
        return $value;
    }
}
