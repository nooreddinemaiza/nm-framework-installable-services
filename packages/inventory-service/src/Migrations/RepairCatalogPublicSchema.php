<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Migrations;

use Core\Database\Schema\SchemaBuilderInterface;

final class RepairCatalogPublicSchema extends CatalogMigration
{
    public function up(SchemaBuilderInterface $schema): void
    {
        (new AddCatalogReferenceSlugs())->up($schema);
        (new AddCatalogSeoTagsAndVariantCost())->up($schema);
        (new AddCatalogTrendMetrics())->up($schema);
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        // This reconciliation must not remove columns supplied by prior migrations.
    }
}
