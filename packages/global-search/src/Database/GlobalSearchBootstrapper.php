<?php

declare(strict_types=1);

namespace NM\Services\GlobalSearch\Database;

use App\Models\Group;
use App\Models\GroupJoinRequest;
use App\Models\GroupUser;
use App\Models\User;
use Core\Database\ConnectionManager;
use Core\Database\Schema\MySqlSchemaBuilder;
use Core\Database\Schema\PostgresSchemaBuilder;
use Core\Database\Schema\SchemaBuilderInterface;
use Core\Database\Schema\SqliteSchemaBuilder;
use NM\Services\GlobalSearch\Models\GlobalSearchSetting;
use NM\Services\GlobalSearch\Search\GlobalSearchService;
use NM\Services\GlobalSearch\Search\SearchRegistry;
use NM\Services\GlobalSearch\Services\GlobalSearchState;
use PDO;
use RuntimeException;

final class GlobalSearchBootstrapper
{
    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly GlobalSearchState $serviceState,
    ) {
    }

    public function __invoke(): void
    {
        SearchRegistry::clear();
        $publicEnabled = false;
        $dashboardEnabled = false;

        if (app()->environment()->getBool('DB_ON', false)) {
            $connection = $this->connections->connection();
            $schema = $this->schema($connection);

            $this->registerCatalogTypes($schema);
            $this->registerArticleTypes($schema);
            $this->registerSalesTypes($schema);
            $this->registerOptionalDashboardTypes($schema);
            $this->registerCoreTypes($schema);

            if ($this->serviceIsFunctional('global-search', GlobalSearchSetting::class, $schema)
                && $this->hasColumns(GlobalSearchSetting::getTable(), $schema, ['id', 'key', 'value'])) {
                $search = app()->get(GlobalSearchService::class);
                $publicEnabled = $search->isAvailable('public');
                $dashboardEnabled = $search->isAvailable('dashboard');
            }
        }

        app()->viewFactory()->share('global_search_enabled', $publicEnabled);
        app()->viewFactory()->share('global_search_dashboard_enabled', $dashboardEnabled);
    }

    private function registerCatalogTypes(SchemaBuilderInterface $schema): void
    {
        $productClass = 'NM\\Services\\InventoryService\\Models\\CatalogProduct';
        $categoryClass = 'NM\\Services\\InventoryService\\Models\\CatalogCategory';
        $brandClass = 'NM\\Services\\InventoryService\\Models\\CatalogBrand';
        if (!$this->serviceState->isActive('inventory-service')) {
            return;
        }

        if ($this->serviceIsFunctional('inventory-service', $productClass, $schema)
            && $this->hasColumns($productClass::getTable(), $schema, [
                'id', 'name', 'description', 'manufacturer', 'slug', 'status', 'archived_at',
            ])) {
            SearchRegistry::register(
                'products',
                'Produits',
                $productClass,
                ['name', 'description', 'manufacturer', 'slug'],
                [
                    'catalog.product.view',
                    'catalog.product.create',
                    'catalog.product.update',
                    'catalog.product.archive',
                    'catalog.product.delete',
                ],
                ['public' => '/products/{slug}', 'dashboard' => '/dashboard/catalog?id={id}'],
            );
        }
        if ($this->serviceIsFunctional('inventory-service', $categoryClass, $schema)
            && $this->hasColumns($categoryClass::getTable(), $schema, ['id', 'name', 'slug', 'status'])) {
            SearchRegistry::register(
                'categories',
                'Catégories',
                $categoryClass,
                ['name'],
                ['catalog.product.view', 'catalog.category.manage'],
                ['public' => '/categories/{slug}', 'dashboard' => '/dashboard/catalog/categories'],
            );
        }
        if ($this->serviceIsFunctional('inventory-service', $brandClass, $schema)
            && $this->hasColumns($brandClass::getTable(), $schema, ['id', 'name', 'slug', 'status'])) {
            SearchRegistry::register(
                'brands',
                'Marques',
                $brandClass,
                ['name'],
                ['catalog.product.view', 'catalog.brand.manage'],
                ['public' => '/brands/{slug}', 'dashboard' => '/dashboard/catalog/brands'],
            );
        }
    }

    private function registerArticleTypes(SchemaBuilderInterface $schema): void
    {
        $articleClass = 'NM\\Services\\Articles\\Models\\Article';
        $categoryClass = 'NM\\Services\\Articles\\Models\\ArticleCategory';
        if ($this->serviceIsFunctional('articles', $articleClass, $schema)
            && $this->hasColumns($articleClass::getTable(), $schema, [
                'id', 'title', 'slug', 'excerpt', 'content', 'status', 'published_at',
            ])) {
            SearchRegistry::register(
                'articles',
                'Articles',
                $articleClass,
                ['title', 'excerpt', 'content', 'slug'],
                ['articles.view', 'articles.create', 'articles.update', 'articles.publish', 'articles.delete'],
                ['public' => '/articles/{slug}', 'dashboard' => '/dashboard/articles'],
            );
        }

        if ($this->serviceState->isActive('articles')
            && $this->serviceIsFunctional('articles', $categoryClass, $schema)
            && $this->hasColumns($categoryClass::getTable(), $schema, ['id', 'name', 'slug'])) {
            SearchRegistry::register(
                'article_categories',
                'Catégories d’articles',
                $categoryClass,
                ['name', 'slug'],
                ['articles.categories.manage'],
                ['dashboard' => '/dashboard/articles'],
                [],
                ['dashboard'],
            );
        }
    }

    private function registerSalesTypes(SchemaBuilderInterface $schema): void
    {
        $class = 'NM\\Services\\SalesService\\Models\\SalesOrderRecord';
        if ($this->serviceIsFunctional('sales-service', $class, $schema)
            && $this->hasColumns($class::getTable(), $schema, [
                'id', 'public_reference', 'profile', 'status', 'currency', 'total_minor',
            ])) {
            SearchRegistry::register(
                'sales_orders',
                'Commandes',
                $class,
                ['public_reference', 'profile', 'status'],
                ['sales.orders.view'],
                ['dashboard' => '/dashboard/sales'],
                [],
                ['dashboard'],
            );
        }
    }

    private function registerOptionalDashboardTypes(SchemaBuilderInterface $schema): void
    {
        $onlinePaymentClass = 'NM\\Services\\OnlinePayments\\Models\\OnlinePaymentAttemptRecord';
        if ($this->serviceIsFunctional('online-payments', $onlinePaymentClass, $schema)
            && $this->serviceState->isActive('sales-service')
            && $this->hasColumns($onlinePaymentClass::getTable(), $schema, [
                'id', 'order_reference', 'provider_reference', 'status', 'amount_minor', 'currency',
            ])) {
            SearchRegistry::register(
                'online_payment_attempts',
                'Paiements en ligne',
                $onlinePaymentClass,
                ['order_reference', 'provider_reference', 'status'],
                ['online_payments.view'],
                ['dashboard' => '/dashboard/online-payments'],
                [],
                ['dashboard'],
            );
        }

        $quoteClass = 'NM\\Services\\Quotes\\Models\\QuoteRecord';
        if ($this->serviceIsFunctional('quotes', $quoteClass, $schema)
            && $this->hasColumns($quoteClass::getTable(), $schema, [
                'id', 'reference', 'customer_name', 'customer_email', 'status', 'total',
            ])) {
            SearchRegistry::register(
                'quotes',
                'Devis',
                $quoteClass,
                ['reference', 'customer_name', 'customer_email'],
                ['quotes.view'],
                ['dashboard' => '/dashboard/quotes'],
                [],
                ['dashboard'],
            );
        }

        if (!$this->serviceState->isActive('sales-service')) {
            return;
        }
        $registerClass = 'NM\\Services\\CashRegister\\Models\\CashRegisterRecord';
        if ($this->serviceIsFunctional('cash-register', $registerClass, $schema)
            && $this->hasColumns($registerClass::getTable(), $schema, [
                'id', 'code', 'name', 'location', 'status',
            ])) {
            SearchRegistry::register(
                'cash_registers',
                'Caisses',
                $registerClass,
                ['code', 'name', 'location'],
                ['cash.registers.view'],
                ['dashboard' => '/dashboard/cash'],
                [],
                ['dashboard'],
            );
        }

        $sessionClass = 'NM\\Services\\CashRegister\\Models\\CashSessionRecord';
        if ($this->serviceIsFunctional('cash-register', $sessionClass, $schema)
            && $this->hasColumns($sessionClass::getTable(), $schema, [
                'id', 'register_code_snapshot', 'register_name_snapshot', 'status', 'opened_at',
            ])) {
            SearchRegistry::register(
                'cash_sessions',
                'Sessions de caisse',
                $sessionClass,
                ['register_code_snapshot', 'register_name_snapshot', 'status'],
                ['cash.payments.view'],
                ['dashboard' => '/dashboard/cash'],
                [],
                ['dashboard'],
            );
        }

        $pageClass = 'NM\\Services\\PageManagement\\Models\\ManagedPage';
        if ($this->serviceIsFunctional('page-management', $pageClass, $schema)
            && $this->hasColumns($pageClass::getTable(), $schema, [
                'id', 'title', 'slug', 'status', 'visibility',
            ])) {
            SearchRegistry::register(
                'pages',
                'Pages',
                $pageClass,
                ['title', 'slug'],
                ['pages.view'],
                ['dashboard' => '/dashboard/pages'],
                [],
                ['dashboard'],
            );
        }
    }

    private function registerCoreTypes(SchemaBuilderInterface $schema): void
    {
        if ($this->hasTables($schema, [User::getTable(), Group::getTable(), GroupUser::getTable()])
            && $this->hasColumns(User::getTable(), $schema, [
                'id',
                'name',
                'username',
                'is_active',
                'email',
                'email_verified_at',
                'two_factor_enabled',
                'two_factor_method',
                'created_at',
                'last_seen_at',
                'profile_image',
            ])
            && $this->hasColumns(GroupUser::getTable(), $schema, ['user_id', 'group_id'])) {
            SearchRegistry::register(
                'users',
                'Utilisateurs',
                User::class,
                ['name', 'username'],
                ['users.view'],
                ['dashboard' => '/dashboard/users?q={username}'],
                [
                    SearchRegistry::button('Voir', 'view'),
                    SearchRegistry::button('Message', 'message'),
                ],
                ['dashboard'],
            );
        }

        if ($this->hasTables($schema, [
            User::getTable(),
            Group::getTable(),
            GroupUser::getTable(),
            GroupJoinRequest::getTable(),
        ])
            && $this->hasColumns(GroupUser::getTable(), $schema, ['user_id', 'group_id'])
            && $this->hasColumns(Group::getTable(), $schema, [
                'id', 'name', 'slug', 'description', 'owner_id', 'is_private', 'is_joinable',
            ])
            && $this->hasColumns(GroupJoinRequest::getTable(), $schema, [
                'group_id', 'user_id', 'message', 'status', 'reviewed_at', 'created_at',
            ])) {
            SearchRegistry::register(
                'groups',
                'Groupes',
                Group::class,
                ['name', 'description', 'slug'],
                ['group.management'],
                ['dashboard' => '/dashboard/groups?q={slug}'],
                [
                    SearchRegistry::button('Voir', 'view'),
                    SearchRegistry::button('Rejoindre', 'join'),
                ],
                ['dashboard'],
            );
        }
    }

    /** @param class-string<\Core\Database\Model> $modelClass */
    private function serviceIsFunctional(
        string $serviceId,
        string $modelClass,
        SchemaBuilderInterface $schema
    ): bool
    {
        return $this->serviceState->isActive($serviceId)
            && class_exists($modelClass)
            && $this->hasTables($schema, [$modelClass::getTable()]);
    }

    /** @param list<string> $tables */
    private function hasTables(SchemaBuilderInterface $schema, array $tables): bool
    {
        foreach ($tables as $table) {
            if (!$schema->hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $columns */
    private function hasColumns(string $table, SchemaBuilderInterface $schema, array $columns): bool
    {
        if (!$schema->hasTable($table)) {
            return false;
        }
        foreach ($columns as $column) {
            if (!$schema->hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }

    private function schema(\Core\Database\Connection $connection): SchemaBuilderInterface
    {
        return match ($connection->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            'mysql' => new MySqlSchemaBuilder($connection),
            'pgsql' => new PostgresSchemaBuilder($connection),
            'sqlite' => new SqliteSchemaBuilder($connection),
            default => throw new RuntimeException('Pilote de base de données non supporté pour la recherche.'),
        };
    }
}
