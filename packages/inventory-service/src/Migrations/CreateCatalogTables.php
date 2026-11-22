<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Migrations;

use Core\Database\Schema\Blueprint;
use Core\Database\Schema\SchemaBuilderInterface;

final class CreateCatalogTables extends CatalogMigration
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('catalog_categories', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('parent_id')->nullable();
            $table->string('name', 190);
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('sort_order')->default(0);
            $table->char('creation_token', 64)->nullable()->unique();
            $this->timestamps($table);
            $table->index(['parent_id', 'sort_order'], 'catalog_categories_parent_order_index');
            $this->foreign($table, 'parent_id', 'catalog_categories', 'catalog_categories_parent_fk');
        });

        $schema->create('catalog_brands', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->string('name', 190)->unique();
            $table->string('status', 20)->default('active');
            $this->timestamps($table);
        });

        $schema->create('catalog_products', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('category_id')->nullable();
            $table->foreignId('brand_id')->nullable();
            $table->string('name', 190);
            $table->mediumText('description')->nullable();
            $table->string('kind', 20)->default('goods');
            $table->string('status', 20)->default('draft');
            $table->string('manufacturer', 190)->nullable();
            $table->string('slug', 220)->unique();
            $table->char('creation_token', 64)->nullable()->unique();
            $table->dateTime('archived_at')->nullable();
            $this->timestamps($table);
            $table->index(['status', 'updated_at'], 'catalog_products_status_updated_index');
            $table->index(['category_id', 'status'], 'catalog_products_category_status_index');
            $table->index(['brand_id', 'status'], 'catalog_products_brand_status_index');
            $this->foreign($table, 'category_id', 'catalog_categories', 'catalog_products_category_fk');
            $this->foreign($table, 'brand_id', 'catalog_brands', 'catalog_products_brand_fk');
        });

        $schema->create('catalog_variants', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('product_id');
            $table->string('sku', 100)->unique();
            $table->decimal('price', 18, 4)->nullable();
            $table->char('currency', 3)->default('MAD');
            $table->boolean('is_default')->default(true);
            $table->char('combination_hash', 64);
            $table->string('label', 190)->default('Principal');
            $table->string('status', 20)->default('active');
            $this->timestamps($table);
            $table->uniqueCombo(
                ['product_id', 'combination_hash'],
                'catalog_variants_product_combination_unique'
            );
            $this->foreign($table, 'product_id', 'catalog_products', 'catalog_variants_product_fk');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        foreach ([
            'catalog_variants',
            'catalog_products',
            'catalog_brands',
            'catalog_categories',
        ] as $table) {
            $schema->dropIfExists($table);
        }
    }
}
