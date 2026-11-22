<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Migrations;

use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Migration;
use Core\Database\Schema\SchemaBuilderInterface;

final class AddCatalogReferenceSlugs extends Migration
{
    public function up(SchemaBuilderInterface $schema): void
    {
        foreach ([
            'catalog_categories' => 'catalog_categories_slug_unique',
            'catalog_brands' => 'catalog_brands_slug_unique',
        ] as $tableName => $indexName) {
            if ($schema->hasTable($tableName) && !$schema->hasColumn($tableName, 'slug')) {
                $schema->alter($tableName, add: static function (Blueprint $table) use ($indexName): void {
                    $table->string('slug', 220)->nullable()->unique(true, $indexName);
                });
            }
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        foreach ([
            'catalog_categories' => 'catalog_categories_slug_unique',
            'catalog_brands' => 'catalog_brands_slug_unique',
        ] as $tableName => $indexName) {
            if ($schema->hasTable($tableName) && $schema->hasColumn($tableName, 'slug')) {
                $schema->alter($tableName, drop: static function (Blueprint $table) use ($indexName): void {
                    $table->dropUnique($indexName);
                    $table->dropColumn('slug');
                });
            }
        }
    }
}
