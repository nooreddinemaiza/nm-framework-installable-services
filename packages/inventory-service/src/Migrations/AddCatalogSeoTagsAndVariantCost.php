<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Migrations;

use Core\Database\Schema\Blueprint;
use Core\Database\Schema\SchemaBuilderInterface;

final class AddCatalogSeoTagsAndVariantCost extends CatalogMigration
{
    public function up(SchemaBuilderInterface $schema): void
    {
        foreach (['catalog_products', 'catalog_categories', 'catalog_brands'] as $tableName) {
            if ($schema->hasTable($tableName) && !$schema->hasColumn($tableName, 'seo_tags')) {
                $schema->alter($tableName, add: static function (Blueprint $table): void {
                    $table->text('seo_tags')->nullable();
                });
            }
        }

        if ($schema->hasTable('catalog_variants') && !$schema->hasColumn('catalog_variants', 'cost_price')) {
            $schema->alter('catalog_variants', add: static function (Blueprint $table): void {
                $table->decimal('cost_price', 18, 4)->nullable();
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        foreach (['catalog_products', 'catalog_categories', 'catalog_brands'] as $tableName) {
            if ($schema->hasTable($tableName) && $schema->hasColumn($tableName, 'seo_tags')) {
                $schema->alter($tableName, drop: static function (Blueprint $table): void {
                    $table->dropColumn('seo_tags');
                });
            }
        }

        if ($schema->hasTable('catalog_variants') && $schema->hasColumn('catalog_variants', 'cost_price')) {
            $schema->alter('catalog_variants', drop: static function (Blueprint $table): void {
                $table->dropColumn('cost_price');
            });
        }
    }
}
