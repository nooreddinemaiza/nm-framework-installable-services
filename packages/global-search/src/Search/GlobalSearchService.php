<?php

declare(strict_types=1);

namespace NM\Services\GlobalSearch\Search;

use App\Models\GroupUser;
use App\Models\User;
use Core\Database\Model;
use Core\Database\QueryBuilder;
use JsonException;
use NM\Services\GlobalSearch\Models\GlobalSearchSetting;
use NM\Services\GlobalSearch\Services\GlobalSearchState;
use RuntimeException;

final class GlobalSearchService
{
    private const CONFIG_KEY = 'search.enabled_types';
    private const PAGE_SIZE = 20;

    public function __construct(private readonly GlobalSearchState $serviceState)
    {
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function availableTypes(string $scope, ?User $user = null): array
    {
        $this->assertAvailable($scope);
        $configured = $this->configuredTypes($scope);
        $types = [];
        $catalogAvailable = !array_intersect($configured, ['products', 'categories', 'brands'])
            || $this->serviceState->isActive('inventory-service');

        foreach (SearchRegistry::all($scope) as $id => $definition) {
            if (!in_array($id, $configured, true)) {
                continue;
            }
            if ($this->isCatalogType($id) && !$catalogAvailable) {
                continue;
            }
            if ($scope === 'dashboard' && !$this->canViewType($user, $definition['permissions'])) {
                continue;
            }

            $types[] = ['id' => $id, 'label' => $definition['label']];
        }

        return $types;
    }

    /**
     * @param list<string> $selectedTypes
     * @return array{results: list<array{type: string, label: string, title: string, excerpt: string, url: string, actions: list<array<string, mixed>>}>, total: int, page: int, per_page: int, pages: int}
     */
    public function search(
        string $term,
        array $selectedTypes,
        string $scope,
        ?User $user,
        int $page = 1,
        int $perPage = self::PAGE_SIZE,
    ): array {
        $this->assertAvailable($scope);
        $term = trim($term);
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));

        $available = $this->availableTypes($scope, $user);
        $availableIds = array_column($available, 'id');
        $selected = $selectedTypes === []
            ? $availableIds
            : array_values(array_intersect($availableIds, $selectedTypes));
        $userGroupIds = $scope === 'dashboard' && $user instanceof User
            ? array_map('intval', $user->groupIds())
            : [];
        $canMessage = $scope === 'dashboard'
            && in_array('users', $selected, true)
            && $user instanceof User
            && $user->hasPermission('conversations.start')
            && $this->serviceState->isActive('messaging');
        $counts = [];
        $total = 0;

        foreach ($selected as $type) {
            $count = $this->queryFor($type, $term, $scope, $user, $userGroupIds)->count();
            $counts[$type] = $count;
            $total += $count;
        }

        $offset = ($page - 1) * $perPage;
        $remaining = $perPage;
        $results = [];
        $skipped = 0;

        foreach ($selected as $type) {
            $count = $counts[$type];
            if ($remaining === 0) {
                break;
            }
            if ($offset >= $skipped + $count) {
                $skipped += $count;
                continue;
            }

            $typeOffset = max(0, $offset - $skipped);
            $titleColumn = $this->titleColumn($type);
            $rows = $this->queryFor($type, $term, $scope, $user, $userGroupIds)
                ->select('id', $titleColumn, ...$this->resultFields($type))
                ->orderBy($titleColumn, 'ASC')
                ->orderBy('id', 'ASC')
                ->offset($typeOffset)
                ->limit($remaining)
                ->get();

            foreach ($rows as $row) {
                $attributes = $row instanceof Model ? $row->toArray() : (array) $row;
                $url = $this->resultUrl($type, $attributes, $scope);
                $results[] = [
                    'type' => $type,
                    'label' => SearchRegistry::all($scope)[$type]['label'],
                    'title' => (string) ($attributes[$titleColumn] ?? ''),
                    'excerpt' => $this->resultExcerpt($type, $attributes),
                    'url' => $url,
                    'actions' => $this->resultActions(
                        $type,
                        $attributes,
                        $url,
                        $scope,
                        $user,
                        $userGroupIds,
                        $canMessage,
                        $row instanceof Model ? $row : null
                    ),
                ];
            }
            $remaining -= count($rows);
            $skipped += $count;
        }

        return [
            'results' => $results,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /**
     * @return list<string>
     */
    public function configuredTypes(string $scope = 'public'): array
    {
        if (!in_array($scope, ['public', 'dashboard'], true)) {
            throw new \InvalidArgumentException('Espace de recherche inconnu.');
        }

        $setting = GlobalSearchSetting::query()->where('key', '=', self::CONFIG_KEY)->first();
        if (!$setting instanceof GlobalSearchSetting) {
            return [];
        }

        try {
            $types = json_decode((string) $setting->value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('La configuration du service de recherche est invalide.', 0, $exception);
        }

        if (!is_array($types)) {
            throw new RuntimeException('La configuration du service de recherche est invalide.');
        }

        if (!array_is_list($types)) {
            $types = $types[$scope] ?? [];
            if (!is_array($types)) {
                throw new RuntimeException('La configuration du service de recherche est invalide.');
            }
        }

        return array_values(array_intersect(array_keys(SearchRegistry::all($scope)), $types));
    }

    public function isAvailable(string $scope = 'public'): bool
    {
        if (!in_array($scope, ['public', 'dashboard'], true)) {
            throw new \InvalidArgumentException('Espace de recherche inconnu.');
        }

        return $this->serviceState->isEnabled()
            && $this->configuredTypes($scope) !== [];
    }

    public function assertAvailable(string $scope = 'public'): void
    {
        $this->assertEnabled();
        if (!in_array($scope, ['public', 'dashboard'], true)) {
            throw new \InvalidArgumentException('Espace de recherche inconnu.');
        }
        if ($this->configuredTypes($scope) === []) {
            throw new \Core\Exceptions\Http\ServiceNotInstalledException(
                'Aucun contenu de recherche n’est configuré pour cet espace.'
            );
        }
    }

    /**
     * @param list<string> $publicTypes
     * @param list<string> $dashboardTypes
     */
    public function saveConfiguration(array $publicTypes, array $dashboardTypes): void
    {
        $publicTypes = array_values(array_intersect(
            array_keys(SearchRegistry::all('public')),
            array_unique($publicTypes)
        ));
        $dashboardTypes = array_values(array_intersect(
            array_keys(SearchRegistry::all('dashboard')),
            array_unique($dashboardTypes)
        ));

        $encoded = json_encode([
            'public' => $publicTypes,
            'dashboard' => $dashboardTypes,
        ], JSON_THROW_ON_ERROR);
        $setting = GlobalSearchSetting::query()->where('key', '=', self::CONFIG_KEY)->first();
        if ($setting instanceof GlobalSearchSetting) {
            $setting->update(['value' => $encoded]);
            return;
        }

        GlobalSearchSetting::create(['key' => self::CONFIG_KEY, 'value' => $encoded]);
    }

    private function queryFor(
        string $type,
        string $term,
        string $scope,
        ?User $user,
        array $userGroupIds
    ): QueryBuilder {
        $definition = SearchRegistry::all($scope)[$type] ?? throw new \InvalidArgumentException('Type de recherche inconnu.');
        $model = $definition['model'];
        $query = $model::query()->search($definition['fields'], $term);

        if ($scope === 'public') {
            $query->where('status', '=', $type === 'articles' ? 'published' : 'active');
            if ($type === 'products') {
                $query->whereNull('archived_at');
            } elseif ($type === 'articles') {
                $query->whereNull('archived_at')
                    ->where(static function (QueryBuilder $builder): void {
                        $builder->whereNull('published_at')
                            ->orWhere('published_at', '<=', date('Y-m-d H:i:s'));
                    });
            }
        }
        if ($scope === 'dashboard' && $type === 'users') {
            if (!$user instanceof User) {
                throw new \InvalidArgumentException('Un utilisateur est requis pour la recherche du tableau de bord.');
            }
            $this->restrictUsersToSharedGroups(
                $query,
                (int) $user->getKey(),
                $userGroupIds,
                $user->isSuper()
            );
        } elseif ($scope === 'dashboard' && $type === 'groups') {
            if (!$user instanceof User) {
                throw new \InvalidArgumentException('Un utilisateur est requis pour la recherche du tableau de bord.');
            }
            $this->restrictGroupsToVisibleGroups(
                $query,
                (int) $user->getKey(),
                $userGroupIds,
                $user->isSuper()
            );
        }

        return $query;
    }

    private function resultUrl(string $type, array $attributes, string $scope): string
    {
        $template = SearchRegistry::all($scope)[$type]['links'][$scope]
            ?? throw new RuntimeException('Aucun lien n’est configuré pour ce résultat.');

        return preg_replace_callback(
            '/\{([a-z][a-z0-9_]*)\}/',
            static function (array $matches) use ($attributes): string {
                if (!array_key_exists($matches[1], $attributes) || !is_scalar($attributes[$matches[1]])) {
                    throw new RuntimeException('Une valeur requise par le lien de recherche est absente.');
                }

                return rawurlencode((string) $attributes[$matches[1]]);
            },
            $template
        ) ?? throw new RuntimeException('Le lien du résultat de recherche est invalide.');
    }

    /**
     * @return list<string>
     */
    private function resultFields(string $type): array
    {
        return match ($type) {
            'products' => ['slug', 'description'],
            'categories', 'brands' => ['slug'],
            'articles' => ['slug', 'excerpt', 'content'],
            'article_categories' => ['slug'],
            'sales_orders' => ['status', 'currency', 'total_minor'],
            'online_payment_attempts' => ['provider_reference', 'status', 'amount_minor', 'currency'],
            'quotes' => ['customer_name', 'customer_email', 'status', 'currency', 'total'],
            'cash_registers' => ['location', 'status'],
            'cash_sessions' => ['register_code_snapshot', 'status'],
            'pages' => ['slug', 'status', 'visibility'],
            'users' => [
                'username',
                'email',
                'email_verified_at',
                'two_factor_enabled',
                'two_factor_method',
                'created_at',
                'last_seen_at',
                'profile_image',
                'is_active',
            ],
            'groups' => ['slug', 'description', 'is_private', 'is_joinable', 'owner_id'],
            default => [],
        };
    }

    private function titleColumn(string $type): string
    {
        return match ($type) {
            'articles', 'pages' => 'title',
            'article_categories', 'cash_registers' => 'name',
            'sales_orders' => 'public_reference',
            'online_payment_attempts' => 'order_reference',
            'quotes' => 'reference',
            'cash_sessions' => 'register_name_snapshot',
            default => 'name',
        };
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function resultExcerpt(string $type, array $attributes): string
    {
        return match ($type) {
            'users' => '@' . (string) ($attributes['username'] ?? ''),
            'sales_orders' => trim(
                (string) ($attributes['status'] ?? '') . ' · '
                . (string) ($attributes['total_minor'] ?? '') . ' '
                . (string) ($attributes['currency'] ?? '')
            ),
            'online_payment_attempts' => trim(
                (string) ($attributes['status'] ?? '') . ' · '
                . (string) ($attributes['amount_minor'] ?? '') . ' '
                . (string) ($attributes['currency'] ?? '')
            ),
            'quotes' => trim(
                (string) ($attributes['customer_name'] ?? '') . ' · '
                . (string) ($attributes['status'] ?? '') . ' · '
                . (string) ($attributes['total'] ?? '') . ' '
                . (string) ($attributes['currency'] ?? '')
            ),
            'cash_registers' => trim(
                (string) ($attributes['location'] ?? '') . ' · '
                . (string) ($attributes['status'] ?? '')
            ),
            'cash_sessions' => trim(
                (string) ($attributes['register_code_snapshot'] ?? '') . ' · '
                . (string) ($attributes['status'] ?? '')
            ),
            'pages' => trim(
                (string) ($attributes['slug'] ?? '') . ' · '
                . (string) ($attributes['status'] ?? '')
            ),
            'article_categories' => (string) ($attributes['slug'] ?? ''),
            default => $this->excerpt((string) (
                $type === 'articles' && trim((string) ($attributes['excerpt'] ?? '')) !== ''
                    ? $attributes['excerpt']
                    : ($attributes['description'] ?? $attributes['content'] ?? '')
            )),
        };
    }

    private function restrictUsersToSharedGroups(
        QueryBuilder $query,
        int $userId,
        array $groupIds,
        bool $isSuper
    ): void {
        $query->where('is_active', '=', 1)
            ->where('id', '!=', $userId);
        if ($isSuper) {
            return;
        }

        if ($groupIds === []) {
            $query->where('id', '=', 0);
            return;
        }

        $query->whereExists(static function (QueryBuilder $membership) use ($groupIds): void {
            $membership->table(GroupUser::getTable() . ' as shared_group_user')
                ->whereColumn('shared_group_user.user_id', '=', 'users.id')
                ->whereIn('shared_group_user.group_id', $groupIds);
        });
    }

    private function restrictGroupsToVisibleGroups(
        QueryBuilder $query,
        int $userId,
        array $groupIds,
        bool $isSuper
    ): void {
        if ($isSuper) {
            return;
        }

        $query->where(function (QueryBuilder $visibility) use ($groupIds, $userId): void {
            $visibility->where('is_private', '=', 0);
            if ($groupIds !== []) {
                $visibility->orWhereIn('id', $groupIds);
            }
            $visibility->orWhere('owner_id', '=', $userId);
        });
    }

    /**
     * @param array<string, mixed> $attributes
     * @return list<array<string, mixed>>
     */
    private function resultActions(
        string $type,
        array $attributes,
        string $url,
        string $scope,
        ?User $user,
        array $userGroupIds,
        bool $canMessage,
        ?Model $row
    ): array {
        if ($scope !== 'dashboard') {
            return [];
        }

        $buttons = SearchRegistry::all($scope)[$type]['buttons'] ?? [];
        $actions = [];
        foreach ($buttons as $button) {
            if ($button['action'] === 'view') {
                $action = [...$button, 'url' => $url];
                if (in_array($type, ['users', 'groups'], true)) {
                    $action['details'] = $this->resultDetails($type, $attributes, $row);
                }
                $actions[] = $action;
                continue;
            }

            if (
                $button['action'] === 'message'
                && $type === 'users'
                && $canMessage
                && $user instanceof User
                && (int) ($attributes['id'] ?? 0) !== (int) $user->getKey()
            ) {
                $actions[] = [
                    ...$button,
                    'user' => [
                        'id' => (int) $attributes['id'],
                        'name' => (string) ($attributes['name'] ?? ''),
                    ],
                ];
                continue;
            }

            if ($button['action'] === 'join' && $type === 'groups' && $user instanceof User) {
                $groupId = (int) ($attributes['id'] ?? 0);
                $isMember = in_array($groupId, $userGroupIds, true)
                    || (int) ($attributes['owner_id'] ?? 0) === (int) $user->getKey();
                if ($groupId > 0 && !$isMember && !(bool) ($attributes['is_private'] ?? false)) {
                    $actions[] = [
                        ...$button,
                        'group_id' => $groupId,
                        'mode' => (bool) ($attributes['is_joinable'] ?? false) ? 'request' : 'direct',
                    ];
                }
            }
        }

        return $actions;
    }

    private function excerpt(string $description): string
    {
        $description = trim(strip_tags($description));
        if (mb_strlen($description) > 160) {
            $description = mb_substr($description, 0, 157) . '...';
        }

        return $description;
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function resultDetails(string $type, array $attributes, ?Model $row): array
    {
        if ($type === 'users') {
            $roles = $row instanceof User ? $row->roles() : [];
            $role = $roles[0] ?? null;

            return [
                'type' => 'user',
                'id' => (int) ($attributes['id'] ?? 0),
                'name' => (string) ($attributes['name'] ?? ''),
                'username' => (string) ($attributes['username'] ?? ''),
                'profile_image' => '/dashboard/assets/profile-image/'
                    . rawurlencode((string) ($attributes['profile_image'] ?? 'default.png')),
                'is_active' => (bool) ($attributes['is_active'] ?? false),
                'role' => $role instanceof Model ? (string) $role->getAttribute('name') : '—',
                'uses_tfa' => (bool) ($attributes['two_factor_enabled'] ?? false),
                'tfa_method' => (string) ($attributes['two_factor_method'] ?? ''),
                'email' => (string) ($attributes['email'] ?? ''),
                'email_verified_at' => $attributes['email_verified_at'] ?? null,
                'created_at' => $attributes['created_at'] ?? null,
                'last_activity' => $attributes['last_seen_at'] ?? null,
            ];
        }

        return [
            'type' => 'group',
            'name' => (string) ($attributes['name'] ?? ''),
            'slug' => (string) ($attributes['slug'] ?? ''),
            'description' => (string) ($attributes['description'] ?? ''),
            'is_private' => (bool) ($attributes['is_private'] ?? false),
            'is_joinable' => (bool) ($attributes['is_joinable'] ?? false),
        ];
    }

    private function canViewType(?User $user, array $permissions): bool
    {
        return $user !== null
            && ($user->isSuper()
                || (
                    $user->highestRoleLevel() >= User::MODERATOR_LEVEL
                    && array_filter($permissions, static fn(string $permission): bool => $user->hasPermission($permission)) !== []
                ));
    }

    private function isCatalogType(string $type): bool
    {
        return in_array($type, ['products', 'categories', 'brands'], true);
    }

    private function assertEnabled(): void
    {
        $this->serviceState->assertEnabled();
    }
}
