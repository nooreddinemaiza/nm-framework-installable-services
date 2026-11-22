<?php

declare(strict_types=1);

namespace NM\Services\GlobalSearch\Search;

use Core\Database\Model;
use InvalidArgumentException;

final class SearchRegistry
{
    private const ACTIONS = ['view', 'message', 'join'];

    /**
     * @var array<string, array{
     *     label: string,
     *     model: class-string<Model>,
     *     fields: list<string>,
     *     permissions: list<string>,
     *     scopes: list<string>,
     *     links: array<string, string>,
     *     buttons: list<array{name: string, action: string}>
     * }>
     */
    private static array $types = [];

    /**
     * @param class-string<Model> $model
     * @param list<string> $fields
     * @param list<string> $permissions
     * @param list<string> $scopes
     * @param array<string, string> $links
     * @param list<array{name: string, action: string}> $buttons
     */
    public static function register(
        string $id,
        string $label,
        string $model,
        array $fields,
        array $permissions,
        array $links,
        array $buttons = [],
        array $scopes = ['public', 'dashboard'],
    ): void {
        if (
            !preg_match('/^[a-z][a-z0-9_]*$/', $id)
            || !is_subclass_of($model, Model::class)
            || $fields === []
            || $permissions === []
            || $scopes === []
            || array_diff($scopes, ['public', 'dashboard']) !== []
        ) {
            throw new InvalidArgumentException('La définition du type de recherche est invalide.');
        }

        foreach ($scopes as $scope) {
            $url = $links[$scope] ?? null;
            if (
                !is_string($url)
                || !str_starts_with($url, '/')
                || str_starts_with($url, '//')
                || str_contains($url, '\\')
                || preg_match('/[\r\n\0]/', $url) === 1
            ) {
                throw new InvalidArgumentException('Chaque espace de recherche doit avoir un lien local valide.');
            }
        }

        foreach ($buttons as $button) {
            if (
                !is_array($button)
                || !is_string($button['name'] ?? null)
                || trim($button['name']) === ''
                || !in_array($button['action'] ?? null, self::ACTIONS, true)
            ) {
                throw new InvalidArgumentException('Un bouton de recherche est invalide.');
            }
        }

        self::$types[$id] = [
            'label' => $label,
            'model' => $model,
            'fields' => array_values(array_unique($fields)),
            'permissions' => array_values(array_unique($permissions)),
            'scopes' => array_values(array_unique($scopes)),
            'links' => $links,
            'buttons' => array_values($buttons),
        ];
    }

    /**
     * @return array{name: string, action: string}
     */
    public static function button(string $name, string $action): array
    {
        if (trim($name) === '' || !in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException('Le bouton de recherche est invalide.');
        }

        return ['name' => trim($name), 'action' => $action];
    }

    /**
     * @return array<string, array{
     *     label: string,
     *     model: class-string<Model>,
     *     fields: list<string>,
     *     permissions: list<string>,
     *     scopes: list<string>,
     *     links: array<string, string>,
     *     buttons: list<array{name: string, action: string}>
     * }>
     */
    public static function all(?string $scope = null): array
    {
        if ($scope === null) {
            return self::$types;
        }
        if (!in_array($scope, ['public', 'dashboard'], true)) {
            throw new InvalidArgumentException('Espace de recherche inconnu.');
        }

        return array_filter(
            self::$types,
            static fn(array $definition): bool => in_array($scope, $definition['scopes'], true)
        );
    }

    public static function clear(): void
    {
        self::$types = [];
    }
}
