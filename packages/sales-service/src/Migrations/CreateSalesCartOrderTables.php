<?php

declare(strict_types=1);

namespace NM\Services\SalesService\Migrations;

use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Migration;
use Core\Database\Schema\SchemaBuilderInterface;

final class CreateSalesCartOrderTables extends Migration
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('sales_carts', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('application_id');
            $table->foreignId('tenant_id')->nullable();
            $table->foreignId('customer_id')->nullable();
            $table->string('profile', 40);
            $table->string('status', 20)->default('open');
            $table->char('currency', 3);
            $table->string('service_mode', 30)->nullable();
            $table->json('service_details_json');
            $table->unsignedInteger('version')->default(1);
            $table->dateTime('expires_at')->nullable();
            $table->foreignId('order_id')->nullable();
            $table->timestamps();
            $table->index(['application_id', 'status', 'expires_at'], 'sales_carts_expiry_index');
            $table->index(['application_id', 'customer_id', 'created_at'], 'sales_carts_customer_index');
        });

        $schema->create('sales_cart_items', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('application_id');
            $table->foreignId('tenant_id')->nullable();
            $table->foreignId('cart_id');
            $table->string('item_reference', 190);
            $table->string('label_snapshot', 190);
            $table->decimal('quantity', 18, 6);
            $table->string('unit', 30);
            $table->bigInteger('unit_amount_minor');
            $table->char('currency', 3);
            $table->json('modifiers_json');
            $table->string('price_source', 80);
            $table->dateTime('priced_at');
            $table->timestamps();
            $table->index(['application_id', 'cart_id'], 'sales_cart_items_cart_index');
        });

        $schema->create('sales_idempotency', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('application_id');
            $table->foreignId('tenant_id')->nullable();
            $table->string('operation', 80);
            $table->string('idempotency_key', 190);
            $table->char('request_hash', 64);
            $table->json('response_json')->nullable();
            $table->string('status', 20)->default('processing');
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();
            $table->uniqueCombo(
                ['application_id', 'operation', 'idempotency_key'],
                'sales_idempotency_scope_unique'
            );
        });

        $schema->create('sales_orders', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('application_id');
            $table->foreignId('tenant_id')->nullable();
            $table->foreignId('cart_id')->nullable();
            $table->foreignId('customer_id')->nullable();
            $table->string('profile', 40);
            $table->string('status', 30);
            $table->char('currency', 3);
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('fee_minor')->default(0);
            $table->bigInteger('total_minor');
            $table->json('details_json');
            $table->string('public_reference', 20)->nullable()->unique(true, 'sales_orders_public_reference_code_unique');
            $table->char('public_reference_hash', 64)->nullable()->unique(true, 'sales_orders_public_reference_unique');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->uniqueCombo(['application_id', 'cart_id'], 'sales_orders_application_cart_unique');
            $table->index(['application_id', 'status', 'created_at'], 'sales_orders_status_index');
            $table->index(['application_id', 'customer_id', 'created_at'], 'sales_orders_customer_index');
        });

        $schema->create('sales_order_items', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('application_id');
            $table->foreignId('tenant_id')->nullable();
            $table->foreignId('order_id');
            $table->string('item_reference', 190);
            $table->string('label_snapshot', 190);
            $table->decimal('quantity', 18, 6);
            $table->string('unit', 30);
            $table->bigInteger('unit_amount_minor');
            $table->bigInteger('line_amount_minor');
            $table->char('currency', 3);
            $table->json('modifiers_json');
            $table->json('tax_snapshot_json');
            $table->timestamps();
            $table->index(['application_id', 'order_id'], 'sales_order_items_order_index');
        });

        $schema->create('sales_order_transitions', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('application_id');
            $table->foreignId('tenant_id')->nullable();
            $table->foreignId('order_id');
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('actor_ref', 120)->nullable();
            $table->string('reason', 190)->nullable();
            $table->dateTime('created_at');
            $table->index(['application_id', 'order_id', 'created_at'], 'sales_order_transitions_order_index');
        });

        $schema->create('sales_invoice_sequences', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('application_id');
            $table->foreignId('tenant_id')->nullable();
            $table->string('series', 40);
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();
            $table->uniqueCombo(
                ['application_id', 'series'],
                'sales_invoice_sequences_scope_unique'
            );
        });

        $schema->create('sales_documents', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('application_id');
            $table->foreignId('tenant_id')->nullable();
            $table->foreignId('order_id');
            $table->string('kind', 20);
            $table->string('series', 40);
            $table->unsignedInteger('number');
            $table->string('status', 20);
            $table->char('currency', 3);
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('tax_minor');
            $table->bigInteger('total_minor');
            $table->json('snapshot_json');
            $table->char('content_hash', 64);
            $table->dateTime('issued_at');
            $table->uniqueCombo(
                ['application_id', 'series', 'number'],
                'sales_documents_number_unique'
            );
            $table->index(['application_id', 'order_id', 'kind'], 'sales_documents_order_index');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        foreach ([
            'sales_documents',
            'sales_invoice_sequences',
            'sales_order_transitions',
            'sales_order_items',
            'sales_orders',
            'sales_idempotency',
            'sales_cart_items',
            'sales_carts',
        ] as $table) {
            $schema->dropIfExists($table);
        }
    }
}
