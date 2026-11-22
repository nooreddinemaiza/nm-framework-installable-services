<?php

declare(strict_types=1);

namespace NM\Services\SalesService\Migrations;

use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Migration;
use Core\Database\Schema\SchemaBuilderInterface;

final class CreateSalesCoreTables extends Migration
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('sales_applications', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('tenant_id')->nullable();
            $table->string('code', 80)->unique();
            $table->string('name', 160);
            $table->string('profile', 40)->default('generic');
            $table->string('status', 20)->default('active');
            $table->char('currency', 3)->default('MAD');
            $table->string('locale', 12)->default('fr');
            $table->json('settings_json');
            $table->timestamps();
            $table->index(['tenant_id', 'status'], 'sales_applications_tenant_status_index');
        });

        $schema->create('sales_application_modules', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('application_id');
            $table->foreignId('tenant_id')->nullable();
            $table->string('module_key', 60);
            $table->string('version', 24)->default('1.0.0');
            $table->string('status', 20)->default('disabled');
            $table->json('config_json');
            $table->timestamps();
            $table->uniqueCombo(
                ['application_id', 'module_key'],
                'sales_application_modules_application_module_unique'
            );
            $table->index(['application_id', 'status'], 'sales_application_modules_status_index');
        });

        $schema->create('sales_api_keys', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('application_id');
            $table->foreignId('tenant_id')->nullable();
            $table->string('name', 120);
            $table->char('key_hash', 64)->unique();
            $table->json('scopes_json');
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->dateTime('last_used_at')->nullable();
            $table->timestamps();
            $table->index(['application_id', 'revoked_at'], 'sales_api_keys_application_revoked_index');
        });

        $schema->create('sales_connectors', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('application_id');
            $table->foreignId('tenant_id')->nullable();
            $table->string('extension_id', 120);
            $table->string('type', 32);
            $table->unsignedInteger('priority')->default(100);
            $table->string('status', 20)->default('disabled');
            $table->json('permissions_json');
            $table->text('config_ciphertext')->nullable();
            $table->timestamps();
            $table->uniqueCombo(
                ['application_id', 'extension_id', 'type'],
                'sales_connectors_application_extension_type_unique'
            );
            $table->index(['application_id', 'type', 'status', 'priority'], 'sales_connectors_lookup_index');
        });

        $schema->create('sales_outbox', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('application_id');
            $table->foreignId('tenant_id')->nullable();
            $table->uuid('event_id')->unique();
            $table->string('event_type', 100);
            $table->string('aggregate_type', 60);
            $table->string('aggregate_id', 80);
            $table->json('payload_json');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('available_at');
            $table->dateTime('delivered_at')->nullable();
            $table->string('last_error_code', 100)->nullable();
            $table->timestamps();
            $table->index(['status', 'available_at', 'id'], 'sales_outbox_claim_index');
            $table->index(['application_id', 'aggregate_type', 'aggregate_id'], 'sales_outbox_aggregate_index');
        });

        $schema->create('sales_inbox', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('application_id');
            $table->foreignId('tenant_id')->nullable();
            $table->foreignId('connector_id');
            $table->string('external_event_id', 190);
            $table->string('status', 20)->default('received');
            $table->dateTime('received_at');
            $table->dateTime('processed_at')->nullable();
            $table->string('result_code', 100)->nullable();
            $table->uniqueCombo(
                ['application_id', 'connector_id', 'external_event_id'],
                'sales_inbox_event_unique'
            );
        });

        $schema->create('sales_audit_log', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('application_id');
            $table->foreignId('tenant_id')->nullable();
            $table->string('actor_ref', 120)->nullable();
            $table->string('action', 100);
            $table->string('subject_type', 80);
            $table->string('subject_id', 100);
            $table->json('metadata_json');
            $table->char('previous_hash', 64)->nullable();
            $table->char('entry_hash', 64);
            $table->dateTime('created_at');
            $table->index(['application_id', 'subject_type', 'subject_id'], 'sales_audit_subject_index');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        foreach ([
            'sales_audit_log',
            'sales_inbox',
            'sales_outbox',
            'sales_connectors',
            'sales_api_keys',
            'sales_application_modules',
            'sales_applications',
        ] as $table) {
            $schema->dropIfExists($table);
        }
    }
}
