<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Migrations;

use Core\Database\Schema\Blueprint;
use Core\Database\Schema\SchemaBuilderInterface;
use Core\Database\Schema\SqliteSchemaBuilder;

final class IncreaseCatalogProductDescriptionCapacity extends CatalogMigration
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema instanceof SqliteSchemaBuilder) {
            return;
        }

        if (!$schema->hasTable('catalog_products') || !$schema->hasColumn('catalog_products', 'description')) {
            return;
        }

        $schema->alter('catalog_products', modify: static function (Blueprint $table): void {
            $table->mediumText('description')->nullable();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        // Keep the larger type on rollback rather than risk truncating saved descriptions.
    }
}
