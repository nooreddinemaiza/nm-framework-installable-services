<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Migrations;

use Core\Database\Schema\Migration;
use Core\Database\Schema\Blueprint;
use Core\Database\Schema\SchemaBuilderInterface;

final class RebuildInventoryCatalogForCatalog extends Migration
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('inventory_products')) {
            return;
        }

        (new CreateInventoryOperationsTables())->down($schema);
        (new LegacyInventoryCatalogTables())->down($schema);
        (new CreateInventoryCatalogTables())->up($schema);
        (new CreateInventoryOperationsTables())->up($schema);
        $schema->create('inventory_catalog_identity_marker', static function (Blueprint $table): void {
            $table->id()->unsigned();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('inventory_catalog_identity_marker')) {
            return;
        }

        (new CreateInventoryOperationsTables())->down($schema);
        (new CreateInventoryCatalogTables())->down($schema);
        (new LegacyInventoryCatalogTables())->up($schema);
        (new CreateInventoryOperationsTables())->up($schema);
        $schema->dropIfExists('inventory_catalog_identity_marker');
    }
}
