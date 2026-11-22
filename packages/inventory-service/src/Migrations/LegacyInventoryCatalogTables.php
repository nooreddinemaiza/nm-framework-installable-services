<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Migrations;

use Core\Database\Schema\Blueprint;
use Core\Database\Schema\SchemaBuilderInterface;
use Core\Database\Schema\SqliteSchemaBuilder;

final class LegacyInventoryCatalogTables extends InventoryMigration
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $sqlite = $schema instanceof SqliteSchemaBuilder;

        $schema->create('inventory_categories', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('parent_id')->nullable();
            $table->string('code', 100)->unique();
            $table->string('name', 190);
            $table->dateTime('archived_at')->nullable();
            $this->timestamps($table);
            $this->foreign($table, 'parent_id', 'inventory_categories', 'inventory_categories_parent_fk');
        });

        $schema->create('inventory_brands', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->string('name', 190)->unique();
            $table->dateTime('archived_at')->nullable();
            $this->timestamps($table);
        });

        $schema->create('inventory_products', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('category_id')->nullable();
            $table->foreignId('brand_id')->nullable();
            $table->string('name', 190);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active');
            $table->dateTime('archived_at')->nullable();
            $this->timestamps($table);
            $table->index(['category_id', 'status'], 'inventory_products_category_index');
            $this->foreign($table, 'category_id', 'inventory_categories', 'inventory_products_category_fk');
            $this->foreign($table, 'brand_id', 'inventory_brands', 'inventory_products_brand_fk');
        });

        $schema->create('inventory_variants', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('product_id');
            $table->char('combination_hash', 64);
            $table->string('label', 190);
            $this->timestamps($table);
            $table->uniqueCombo(
                ['product_id', 'combination_hash'],
                'inventory_variants_product_combination_unique'
            );
            $this->foreign($table, 'product_id', 'inventory_products', 'inventory_variants_product_fk');
        });

        $schema->create('inventory_attribute_definitions', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->string('code', 100)->unique();
            $table->string('name', 190);
            $table->string('value_type', 20);
            $table->string('applies_to', 20);
            $this->timestamps($table);
        });

        $schema->create('inventory_attribute_options', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('attribute_id');
            $table->string('code', 100);
            $table->string('label', 190);
            $this->foreign(
                $table,
                'attribute_id',
                'inventory_attribute_definitions',
                'inventory_attribute_options_attribute_fk'
            );
            $table->uniqueCombo(
                ['attribute_id', 'code'],
                'inventory_attribute_options_code_unique'
            );
            $table->uniqueCombo(
                ['id', 'attribute_id'],
                'inventory_attribute_options_id_attribute_unique'
            );
        });

        $schema->create('inventory_product_attribute_values', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('product_id');
            $table->foreignId('attribute_id');
            $table->foreignId('option_id')->nullable();
            $table->text('value_text')->nullable();
            $table->decimal('value_decimal', 18, 6)->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->date('value_date')->nullable();
            $table->uniqueCombo(
                ['product_id', 'attribute_id'],
                'inventory_product_attribute_value_unique'
            );
            $this->foreign(
                $table,
                'product_id',
                'inventory_products',
                'inventory_product_attribute_product_fk'
            );
            $this->foreign(
                $table,
                'attribute_id',
                'inventory_attribute_definitions',
                'inventory_product_attribute_definition_fk'
            );
            $this->foreign(
                $table,
                'option_id',
                'inventory_attribute_options',
                'inventory_product_attribute_option_fk'
            );
        });

        $schema->create('inventory_variant_attribute_values', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('variant_id');
            $table->foreignId('attribute_id');
            $table->foreignId('option_id')->nullable();
            $table->text('value_text')->nullable();
            $table->decimal('value_decimal', 18, 6)->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->date('value_date')->nullable();
            $table->uniqueCombo(
                ['variant_id', 'attribute_id'],
                'inventory_variant_attribute_value_unique'
            );
            $this->foreign(
                $table,
                'variant_id',
                'inventory_variants',
                'inventory_variant_attribute_variant_fk'
            );
            $this->foreign(
                $table,
                'attribute_id',
                'inventory_attribute_definitions',
                'inventory_variant_attribute_definition_fk'
            );
            $this->foreign(
                $table,
                'option_id',
                'inventory_attribute_options',
                'inventory_variant_attribute_option_fk'
            );
        });

        $schema->create('inventory_units', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->string('code', 40)->unique();
            $table->string('name', 100);
            $table->tinyInteger('precision_scale')->unsigned()->default(6);
            $this->timestamps($table);
        });

        $schema->create('inventory_items', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('product_id');
            $table->foreignId('variant_id')->nullable()->unique();
            $table->foreignId('reference_unit_id');
            $table->string('sku', 100)->unique();
            $table->string('type', 20);
            $table->string('status', 20)->default('active');
            $table->boolean('track_inventory')->default(true);
            $table->string('tracking_mode', 20)->default('none');
            $table->boolean('track_expiration')->default(false);
            $table->boolean('allow_decimal_quantity')->default(true);
            $table->tinyInteger('quantity_scale')->unsigned()->default(6);
            $table->boolean('allow_negative_stock')->default(false);
            $table->decimal('min_qty', 18, 6)->nullable();
            $table->decimal('max_qty', 18, 6)->nullable();
            $table->string('picking_strategy', 10)->nullable();
            $table->dateTime('archived_at')->nullable();
            $this->timestamps($table);
            $table->index(['product_id', 'status'], 'inventory_items_product_status_index');
            $this->foreign($table, 'product_id', 'inventory_products', 'inventory_items_product_fk');
            $this->foreign($table, 'variant_id', 'inventory_variants', 'inventory_items_variant_fk');
            $this->foreign($table, 'reference_unit_id', 'inventory_units', 'inventory_items_unit_fk');
        });

        $schema->create('inventory_item_units', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('item_id');
            $table->foreignId('unit_id');
            $table->decimal('conversion_factor', 18, 6);
            $table->string('rounding_mode', 20)->default('reject');
            $table->uniqueCombo(['item_id', 'unit_id'], 'inventory_item_units_item_unit_unique');
            $this->foreign($table, 'item_id', 'inventory_items', 'inventory_item_units_item_fk');
            $this->foreign($table, 'unit_id', 'inventory_units', 'inventory_item_units_unit_fk');
        });

        $schema->create('inventory_item_barcodes', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('item_id');
            $table->string('barcode_type', 20);
            $table->string('barcode', 128);
            $table->dateTime('created_at');
            $table->uniqueCombo(
                ['barcode_type', 'barcode'],
                'inventory_item_barcodes_value_unique'
            );
            $table->index(['item_id'], 'inventory_item_barcodes_item_index');
            $this->foreign($table, 'item_id', 'inventory_items', 'inventory_item_barcodes_item_fk');
        });

        $schema->create('inventory_warehouses', function (Blueprint $table) use ($sqlite): void {
            $table->id()->unsigned();
            $table->string('code', 80)->unique();
            $table->string('name', 190);
            $table->foreignId('default_location_id')->nullable();
            $table->boolean('is_active')->default(true);
            $this->timestamps($table);

            if ($sqlite) {
                $this->foreign(
                    $table,
                    'default_location_id',
                    'inventory_locations',
                    'inventory_warehouses_default_location_fk'
                );
            }
        });

        $schema->create('inventory_locations', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('warehouse_id');
            $table->foreignId('parent_id')->nullable();
            $table->string('code', 100);
            $table->string('name', 190);
            $table->string('location_type', 20)->default('storage');
            $table->boolean('is_available')->default(true);
            $table->boolean('is_active')->default(true);
            $this->timestamps($table);
            $table->uniqueCombo(
                ['warehouse_id', 'code'],
                'inventory_locations_warehouse_code_unique'
            );
            $this->foreign(
                $table,
                'warehouse_id',
                'inventory_warehouses',
                'inventory_locations_warehouse_fk'
            );
            $this->foreign(
                $table,
                'parent_id',
                'inventory_locations',
                'inventory_locations_parent_fk'
            );
        });

        if (!$sqlite) {
            $schema->alter('inventory_warehouses', add: function (Blueprint $table): void {
                $this->foreign(
                    $table,
                    'default_location_id',
                    'inventory_locations',
                    'inventory_warehouses_default_location_fk'
                );
            });
        }

        $schema->create('inventory_item_lots', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('item_id');
            $table->string('lot_code', 120);
            $table->boolean('is_sentinel')->default(false);
            $table->string('status', 20)->default('available');
            $table->date('manufactured_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->string('source_reference', 190)->nullable();
            $this->timestamps($table);
            $table->uniqueCombo(['item_id', 'lot_code'], 'inventory_lots_item_code_unique');
            $this->foreign($table, 'item_id', 'inventory_items', 'inventory_lots_item_fk');
        });

        $schema->create('inventory_serial_numbers', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('item_id');
            $table->string('serial_number', 190)->unique();
            $table->string('status', 20)->default('in_stock');
            $table->foreignId('current_location_id')->nullable();
            $table->date('warranty_starts_at')->nullable();
            $table->date('warranty_ends_at')->nullable();
            $this->timestamps($table);
            $table->index(['item_id', 'status'], 'inventory_serial_item_status_index');
            $this->foreign($table, 'item_id', 'inventory_items', 'inventory_serial_item_fk');
            $this->foreign(
                $table,
                'current_location_id',
                'inventory_locations',
                'inventory_serial_location_fk'
            );
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        if ($schema instanceof SqliteSchemaBuilder && $schema->hasTable('inventory_warehouses')) {
            $schema->raw('UPDATE inventory_warehouses SET default_location_id = NULL');
        } elseif ($schema->hasTable('inventory_warehouses')) {
            $schema->alter('inventory_warehouses', drop: static function (Blueprint $table): void {
                $table->dropForeign('inventory_warehouses_default_location_fk');
            });
        }

        foreach ([
            'inventory_serial_numbers',
            'inventory_item_lots',
            'inventory_locations',
            'inventory_warehouses',
            'inventory_item_barcodes',
            'inventory_item_units',
            'inventory_items',
            'inventory_variant_attribute_values',
            'inventory_product_attribute_values',
            'inventory_attribute_options',
            'inventory_variants',
            'inventory_attribute_definitions',
            'inventory_products',
            'inventory_categories',
            'inventory_brands',
            'inventory_units',
        ] as $table) {
            $schema->dropIfExists($table);
        }
    }
}
