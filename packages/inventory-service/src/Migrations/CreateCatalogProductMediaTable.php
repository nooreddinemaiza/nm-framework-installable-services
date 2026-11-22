<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Migrations;

use Core\Database\Schema\Blueprint;
use Core\Database\Schema\SchemaBuilderInterface;

final class CreateCatalogProductMediaTable extends CatalogMigration
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('catalog_product_media', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('product_id');
            $table->foreignId('media_id')->nullable()->unique();
            $table->string('media_type', 10);
            $table->text('external_url')->nullable();
            $table->string('caption', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $this->timestamps($table);
            $table->index(['product_id', 'sort_order'], 'catalog_product_media_order_index');
            $this->foreign($table, 'product_id', 'catalog_products', 'catalog_product_media_product_fk');
            $this->foreign($table, 'media_id', 'media', 'catalog_product_media_media_fk');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('catalog_product_media');
    }
}
