<?php

declare(strict_types=1);

namespace NM\Services\CashRegister\Migrations;

use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Migration;
use Core\Database\Schema\SchemaBuilderInterface;

final class CreateCashReceipts extends Migration
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('cash_receipts', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->string('receipt_number', 80)->unique();
            $table->string('receipt_type', 16);
            $table->foreignId('session_id');
            $table->foreignId('register_id');
            $table->string('sales_order_id', 20);
            $table->string('sales_payment_id', 20)->unique();
            $table->string('related_sales_payment_id', 20)->nullable();
            $table->string('order_reference', 20);
            $table->string('store_name_snapshot', 160);
            $table->string('business_reference_snapshot', 80)->nullable();
            $table->string('register_code_snapshot', 40);
            $table->string('register_name_snapshot', 120);
            $table->bigInteger('amount_minor');
            $table->bigInteger('cash_received_minor')->nullable();
            $table->bigInteger('change_minor')->default(0);
            $table->char('currency', 3);
            $table->string('payment_method', 30);
            $table->text('reason')->nullable();
            $table->foreignId('actor_user_id');
            $table->dateTime('created_at');

            $table->index(['session_id', 'created_at'], 'cash_receipts_session_created_index');
            $table->index(['order_reference', 'created_at'], 'cash_receipts_order_reference_index');
            $table->foreign('session_id')->references('id')->on('cash_sessions')->restrictOnDelete();
            $table->foreign('register_id')->references('id')->on('cash_registers')->restrictOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('cash_receipts');
    }
}
