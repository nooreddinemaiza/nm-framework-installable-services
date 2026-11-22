<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Migrations;

use Core\Database\Schema\Blueprint;
use Core\Database\Schema\SchemaBuilderInterface;

final class AddCatalogTrendMetrics extends CatalogMigration
{
    public function up(SchemaBuilderInterface $schema): void
    {
        foreach (['catalog_products', 'catalog_categories', 'catalog_brands'] as $tableName) {
            if (!$schema->hasTable($tableName)) {
                continue;
            }

            $addTrending = !$schema->hasColumn($tableName, 'is_trending');
            $addViews = !$schema->hasColumn($tableName, 'views_count');
            if (!$addTrending && !$addViews) {
                continue;
            }

            $schema->alter($tableName, add: static function (Blueprint $table) use ($addTrending, $addViews): void {
                if ($addTrending) {
                    $table->boolean('is_trending')->default(false);
                }
                if ($addViews) {
                    $table->unsignedBigInteger('views_count')->default(0);
                }
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        foreach (['catalog_products', 'catalog_categories', 'catalog_brands'] as $tableName) {
            if (!$schema->hasTable($tableName)) {
                continue;
            }

            $drop = [];
            foreach (['views_count', 'is_trending'] as $column) {
                if ($schema->hasColumn($tableName, $column)) {
                    $drop[] = $column;
                }
            }
            if ($drop === []) {
                continue;
            }

            $schema->alter($tableName, drop: static function (Blueprint $table) use ($drop): void {
                $table->dropColumn(...$drop);
            });
        }
    }
}
