<?php

declare(strict_types=1);

namespace NM\Services\SalesService\Migrations;

use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Migration;
use Core\Database\Schema\SchemaBuilderInterface;

final class CreateSalesPaymentLedger extends Migration
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('sales_payments', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('application_id');
            $table->foreignId('tenant_id')->nullable();
            $table->foreignId('order_id');
            $table->string('operation_type', 16);
            $table->string('method', 30);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->foreignId('related_payment_id')->nullable();
            $table->string('source_module', 40);
            $table->string('idempotency_key', 190);
            $table->string('reference', 190)->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('actor_ref', 120)->nullable();
            $table->dateTime('created_at');

            $table->uniqueCombo(
                ['application_id', 'idempotency_key'],
                'sales_payments_idempotency_unique'
            );
            $table->index(['application_id', 'order_id', 'created_at'], 'sales_payments_order_index');
            $table->index(['related_payment_id', 'operation_type'], 'sales_payments_refunds_index');
            $table->foreign('order_id')->references('id')->on('sales_orders')->restrictOnDelete();
            $table->foreign('related_payment_id')->references('id')->on('sales_payments')->restrictOnDelete();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('sales_payments');
    }
}
