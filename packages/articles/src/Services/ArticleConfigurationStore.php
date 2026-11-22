<?php

declare(strict_types=1);

namespace NM\Services\Articles\Services;

use App\Models\Setting;
use Core\Exceptions\Http\UnprocessableEntityException;

final class ArticleConfigurationStore
{
    private const DEFAULTS = [
        'items_per_page' => 12,
        'view_deduplication_ttl' => 3600,
        'public_popularity_enabled' => true,
    ];

    /** @return array{items_per_page: int, view_deduplication_ttl: int, public_popularity_enabled: bool} */
    public function values(): array
    {
        return [
            'items_per_page' => $this->integer('articles.items_per_page', self::DEFAULTS['items_per_page'], 1, 100),
            'view_deduplication_ttl' => $this->integer(
                'articles.view_deduplication_ttl',
                self::DEFAULTS['view_deduplication_ttl'],
                60,
                2_592_000,
            ),
            'public_popularity_enabled' => $this->boolean(
                'articles.public_popularity_enabled',
                self::DEFAULTS['public_popularity_enabled'],
            ),
        ];
    }

    /** @param array<string, mixed> $input */
    public function save(array $input): void
    {
        $allowed = ['items_per_page', 'view_deduplication_ttl', 'public_popularity_enabled'];
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new UnprocessableEntityException(['configuration' => ['Configuration d’articles inconnue.']]);
        }

        $perPage = filter_var($input['items_per_page'] ?? null, FILTER_VALIDATE_INT);
        $ttl = filter_var($input['view_deduplication_ttl'] ?? null, FILTER_VALIDATE_INT);
        $popular = $input['public_popularity_enabled'] ?? null;
        if ($perPage === false || $perPage < 1 || $perPage > 100) {
            throw new UnprocessableEntityException(['items_per_page' => ['Choisissez un nombre entre 1 et 100.']]);
        }
        if ($ttl === false || $ttl < 60 || $ttl > 2_592_000) {
            throw new UnprocessableEntityException(['view_deduplication_ttl' => ['La durée doit être comprise entre 60 secondes et 30 jours.']]);
        }
        if (!is_string($popular) || !in_array($popular, ['0', '1'], true)) {
            throw new UnprocessableEntityException(['public_popularity_enabled' => ['Choisissez une valeur valide.']]);
        }

        foreach ([
            'articles.items_per_page' => [(string) $perPage, 'integer'],
            'articles.view_deduplication_ttl' => [(string) $ttl, 'integer'],
            'articles.public_popularity_enabled' => [$popular === '1' ? 'true' : 'false', 'boolean'],
        ] as $key => [$value, $type]) {
            $setting = Setting::query()->where('key', '=', $key)->first();
            if ($setting instanceof Setting) {
                $setting->update(['value' => $value, 'type' => $type]);
            } else {
                Setting::create(['key' => $key, 'value' => $value, 'type' => $type]);
            }
        }
    }

    private function integer(string $key, int $default, int $minimum, int $maximum): int
    {
        $setting = Setting::query()->where('key', '=', $key)->first();
        if (!$setting instanceof Setting) {
            return $default;
        }
        $value = filter_var($setting->getAttribute('value'), FILTER_VALIDATE_INT);
        if ($value === false || $value < $minimum || $value > $maximum) {
            throw new \RuntimeException(sprintf('La valeur de configuration des articles [%s] est invalide.', $key));
        }

        return $value;
    }

    private function boolean(string $key, bool $default): bool
    {
        $setting = Setting::query()->where('key', '=', $key)->first();
        if (!$setting instanceof Setting) {
            return $default;
        }
        $value = filter_var($setting->getAttribute('value'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($value === null) {
            throw new \RuntimeException(sprintf('La valeur de configuration des articles [%s] est invalide.', $key));
        }

        return $value;
    }
}
