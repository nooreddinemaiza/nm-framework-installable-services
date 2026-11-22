<?php

declare(strict_types=1);

namespace NM\Services\Quotes\Migrations;

use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Migration;
use Core\Database\Schema\SchemaBuilderInterface;

final class CreateQuotesTables extends Migration
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('quotes', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->string('reference', 64)->unique();
            $table->foreignId('customer_id')->nullable();
            $table->string('customer_name', 190);
            $table->string('customer_email', 190);
            $table->string('customer_phone', 40)->nullable();
            $table->string('customer_company', 190)->nullable();
            $table->text('customer_address')->nullable();
            $table->string('status', 20)->default('pending');
            $table->char('currency', 3)->default('MAD');
            $table->decimal('subtotal', 20, 2)->default('0.00');
            $table->decimal('discount_total', 20, 2)->default('0.00');
            $table->decimal('tax_total', 20, 2)->default('0.00');
            $table->decimal('total', 20, 2)->default('0.00');
            $table->text('customer_note')->nullable();
            $table->text('internal_note')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->foreignId('converted_order_id')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at'], 'quotes_status_created_index');
            $table->index(['customer_email', 'status'], 'quotes_customer_status_index');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });

        $schema->create('quote_items', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('quote_id');
            $table->string('item_type', 40)->default('product');
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('item_reference', 190)->nullable();
            $table->string('item_name', 190);
            $table->text('description')->nullable();
            $table->decimal('quantity', 20, 6)->default('1.000000');
            $table->decimal('unit_price', 20, 2)->default('0.00');
            $table->string('discount_type', 20)->default('none');
            $table->decimal('discount_value', 20, 6)->default('0.000000');
            $table->decimal('tax_rate', 8, 4)->default('0.0000');
            $table->decimal('tax_amount', 20, 2)->default('0.00');
            $table->decimal('line_subtotal', 20, 2)->default('0.00');
            $table->decimal('line_total', 20, 2)->default('0.00');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index(['quote_id', 'position'], 'quote_items_quote_position_index');
            $table->foreign('quote_id')->references('id')->on('quotes')->cascadeOnDelete();
        });

        $schema->create('quote_histories', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('quote_id');
            $table->foreignId('user_id')->nullable();
            $table->string('action', 80);
            $table->text('description')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['quote_id', 'created_at'], 'quote_histories_quote_created_index');
            $table->foreign('quote_id')->references('id')->on('quotes')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('quote_histories');
        $schema->dropIfExists('quote_items');
        $schema->dropIfExists('quotes');
    }
}
