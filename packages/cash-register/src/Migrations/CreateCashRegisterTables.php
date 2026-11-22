<?php

declare(strict_types=1);

namespace NM\Services\CashRegister\Migrations;

use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Migration;
use Core\Database\Schema\SchemaBuilderInterface;

final class CreateCashRegisterTables extends Migration
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('cash_registers', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('location', 120)->nullable();
            $table->string('register_type', 20)->default('physical');
            $table->char('currency', 3);
            $table->bigInteger('suggested_opening_minor')->default(0);
            $table->string('status', 20)->default('active');
            $table->json('allowed_user_ids_json');
            $table->json('allowed_payment_methods_json');
            $table->boolean('allow_refunds')->default(false);
            $table->boolean('allow_cash_outflows')->default(true);
            $table->json('printer_configuration_json')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'name'], 'cash_registers_status_name_index');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        $schema->create('cash_sessions', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('register_id');
            $table->foreignId('active_register_id')->nullable()->unique();
            $table->string('register_code_snapshot', 40);
            $table->string('register_name_snapshot', 120);
            $table->char('currency', 3);
            $table->foreignId('opened_by');
            $table->foreignId('closed_by')->nullable();
            $table->foreignId('variance_approved_by')->nullable();
            $table->string('status', 20)->default('open');
            $table->bigInteger('opening_float_minor');
            $table->bigInteger('expected_cash_minor')->nullable();
            $table->bigInteger('counted_cash_minor')->nullable();
            $table->bigInteger('variance_minor')->nullable();
            $table->text('variance_reason')->nullable();
            $table->dateTime('opened_at');
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();

            $table->index(['register_id', 'opened_at'], 'cash_sessions_register_opened_index');
            $table->index(['status', 'opened_at'], 'cash_sessions_status_opened_index');
            $table->foreign('register_id')->references('id')->on('cash_registers')->restrictOnDelete();
            $table->foreign('opened_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('closed_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('variance_approved_by')->references('id')->on('users')->nullOnDelete();
        });

        $schema->create('cash_movements', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('session_id');
            $table->string('movement_type', 20);
            $table->bigInteger('amount_minor');
            $table->text('reason');
            $table->foreignId('actor_user_id');
            $table->string('idempotency_key', 190)->unique();
            $table->dateTime('created_at');

            $table->index(['session_id', 'created_at'], 'cash_movements_session_created_index');
            $table->foreign('session_id')->references('id')->on('cash_sessions')->restrictOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('cash_movements');
        $schema->dropIfExists('cash_sessions');
        $schema->dropIfExists('cash_registers');
    }
}
