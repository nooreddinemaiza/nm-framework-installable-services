<?php

declare(strict_types=1);

namespace NM\Services\OnlinePayments\Migrations;

use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Migration;
use Core\Database\Schema\SchemaBuilderInterface;

final class CreateOnlinePaymentsTables extends Migration
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('online_payment_configuration', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->char('merchant_country', 2)->default('MA');
            $table->char('currency', 3)->default('MAD');
            $table->boolean('allow_partial_payments')->default(false);
            $table->timestamps();
        });

        $schema->create('online_payment_providers', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->string('code', 60)->unique();
            $table->string('name', 120);
            $table->string('status', 20)->default('disabled');
            $table->text('credentials_ciphertext')->nullable();
            $table->timestamps();
        });

        $schema->create('online_payment_attempts', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('application_id');
            $table->foreignId('order_id');
            $table->foreignId('provider_id');
            $table->foreignId('sales_payment_id')->nullable();
            $table->string('order_reference', 20);
            $table->string('provider_reference', 190)->nullable();
            $table->string('idempotency_key', 190);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 24)->default('creating');
            $table->text('redirect_url')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->timestamps();
            $table->uniqueCombo(['application_id', 'idempotency_key'], 'online_payment_attempt_idempotency_unique');
            $table->uniqueCombo(['provider_id', 'provider_reference'], 'online_payment_attempt_provider_reference_unique');
            $table->index(['application_id', 'status', 'created_at'], 'online_payment_attempt_status_index');
            $table->index(['application_id', 'order_id', 'created_at'], 'online_payment_attempt_order_index');
            $table->foreign('application_id')->references('id')->on('sales_applications')->restrictOnDelete();
            $table->foreign('order_id')->references('id')->on('sales_orders')->restrictOnDelete();
            $table->foreign('provider_id')->references('id')->on('online_payment_providers')->restrictOnDelete();
            $table->foreign('sales_payment_id')->references('id')->on('sales_payments')->restrictOnDelete();
        });

        $schema->create('online_payment_events', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('provider_id');
            $table->foreignId('attempt_id')->nullable();
            $table->string('external_event_id', 190);
            $table->char('payload_sha256', 64);
            $table->string('status', 20)->default('received');
            $table->string('result_code', 100)->nullable();
            $table->dateTime('received_at');
            $table->dateTime('processed_at')->nullable();
            $table->uniqueCombo(['provider_id', 'external_event_id'], 'online_payment_event_provider_external_unique');
            $table->index(['status', 'received_at'], 'online_payment_event_retry_index');
            $table->foreign('provider_id')->references('id')->on('online_payment_providers')->restrictOnDelete();
            $table->foreign('attempt_id')->references('id')->on('online_payment_attempts')->restrictOnDelete();
        });

        $schema->create('online_payment_status_history', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('attempt_id');
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->string('reason_code', 100);
            $table->dateTime('created_at');
            $table->index(['attempt_id', 'created_at'], 'online_payment_history_attempt_created_index');
            $table->foreign('attempt_id')->references('id')->on('online_payment_attempts')->restrictOnDelete();
        });

        $schema->create('online_payment_refunds', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('attempt_id');
            $table->foreignId('actor_user_id');
            $table->string('external_reference', 190)->nullable();
            $table->string('idempotency_key', 190)->unique();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 24)->default('pending');
            $table->string('reason', 500);
            $table->timestamps();
            $table->index(['attempt_id', 'status'], 'online_payment_refund_attempt_status_index');
            $table->foreign('attempt_id')->references('id')->on('online_payment_attempts')->restrictOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('online_payment_refunds');
        $schema->dropIfExists('online_payment_status_history');
        $schema->dropIfExists('online_payment_events');
        $schema->dropIfExists('online_payment_attempts');
        $schema->dropIfExists('online_payment_providers');
        $schema->dropIfExists('online_payment_configuration');
    }
}
