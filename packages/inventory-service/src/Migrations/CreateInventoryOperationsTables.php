<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Migrations;

use Core\Database\Schema\Blueprint;
use Core\Database\Schema\SchemaBuilderInterface;

final class CreateInventoryOperationsTables extends InventoryMigration
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('inventory_stock_operations', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->string('operation_type', 30);
            $table->string('status', 20)->default('draft');
            $table->string('reference', 190)->nullable();
            $table->text('reason')->nullable();
            $table->dateTime('operational_at');
            $table->dateTime('validated_at')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->foreignId('validated_by')->nullable();
            $table->string('source_module', 80)->default('inventory');
            $table->string('idempotency_key', 190);
            $table->string('external_module', 80)->nullable();
            $table->string('external_document_type', 80)->nullable();
            $table->string('external_document_id', 190)->nullable();
            $table->unsignedInteger('version')->default(1);
            $this->timestamps($table);
            $table->uniqueCombo(
                ['source_module', 'idempotency_key'],
                'inventory_operations_idempotency_unique'
            );
            $table->index(
                ['operation_type', 'status', 'operational_at'],
                'inventory_operations_type_status_index'
            );
            $table->index(
                ['external_module', 'external_document_type', 'external_document_id'],
                'inventory_operations_external_reference_index'
            );
            $this->foreign($table, 'created_by', 'users', 'inventory_operations_created_by_fk');
            $this->foreign($table, 'validated_by', 'users', 'inventory_operations_validated_by_fk');
        });

        $schema->create('inventory_operation_lines', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('operation_id');
            $table->foreignId('item_id');
            $table->foreignId('unit_id');
            $table->decimal('quantity_input', 18, 6);
            $table->decimal('conversion_factor', 18, 6);
            $table->decimal('quantity_base', 18, 6);
            $table->foreignId('source_location_id')->nullable();
            $table->foreignId('destination_location_id')->nullable();
            $table->foreignId('lot_id');
            $table->foreignId('serial_id')->nullable();
            $table->text('reason')->nullable();
            $this->timestamps($table);
            $table->index(['item_id', 'operation_id'], 'inventory_operation_lines_item_index');
            $this->foreign(
                $table,
                'operation_id',
                'inventory_stock_operations',
                'inventory_operation_lines_operation_fk'
            );
            $this->foreign($table, 'item_id', 'inventory_items', 'inventory_operation_lines_item_fk');
            $this->foreign($table, 'unit_id', 'inventory_units', 'inventory_operation_lines_unit_fk');
            $this->foreign(
                $table,
                'source_location_id',
                'inventory_locations',
                'inventory_operation_lines_source_location_fk'
            );
            $this->foreign(
                $table,
                'destination_location_id',
                'inventory_locations',
                'inventory_operation_lines_destination_location_fk'
            );
            $this->foreign(
                $table,
                'lot_id',
                'inventory_item_lots',
                'inventory_operation_lines_lot_fk'
            );
            $this->foreign(
                $table,
                'serial_id',
                'inventory_serial_numbers',
                'inventory_operation_lines_serial_fk'
            );
        });

        $schema->create('inventory_stock_transfers', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('operation_id')->unique();
            $table->foreignId('source_warehouse_id');
            $table->foreignId('destination_warehouse_id');
            $table->foreignId('source_location_id');
            $table->foreignId('destination_location_id');
            $table->string('transfer_mode', 20);
            $table->dateTime('shipped_at')->nullable();
            $table->dateTime('received_at')->nullable();
            $this->foreign(
                $table,
                'operation_id',
                'inventory_stock_operations',
                'inventory_transfers_operation_fk'
            );
            $this->foreign(
                $table,
                'source_warehouse_id',
                'inventory_warehouses',
                'inventory_transfers_source_warehouse_fk'
            );
            $this->foreign(
                $table,
                'destination_warehouse_id',
                'inventory_warehouses',
                'inventory_transfers_destination_warehouse_fk'
            );
            $this->foreign(
                $table,
                'source_location_id',
                'inventory_locations',
                'inventory_transfers_source_location_fk'
            );
            $this->foreign(
                $table,
                'destination_location_id',
                'inventory_locations',
                'inventory_transfers_destination_location_fk'
            );
        });

        $schema->create('inventory_stock_counts', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('operation_id')->unique();
            $table->string('concurrent_movement_mode', 20)->default('snapshot_adjust');
            $table->dateTime('opened_at')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $this->foreign(
                $table,
                'operation_id',
                'inventory_stock_operations',
                'inventory_counts_operation_fk'
            );
        });

        $schema->create('inventory_stock_count_lines', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('count_operation_id');
            $table->foreignId('item_id');
            $table->foreignId('location_id');
            $table->foreignId('lot_id');
            $table->decimal('snapshot_qty', 18, 6);
            $table->decimal('final_counted_qty', 18, 6)->nullable();
            $this->timestamps($table);
            $table->uniqueCombo(
                ['count_operation_id', 'item_id', 'location_id', 'lot_id'],
                'inventory_count_scope_unique'
            );
            $this->foreign(
                $table,
                'count_operation_id',
                'inventory_stock_counts',
                'inventory_count_lines_count_fk'
            );
            $this->foreign($table, 'item_id', 'inventory_items', 'inventory_count_lines_item_fk');
            $this->foreign(
                $table,
                'location_id',
                'inventory_locations',
                'inventory_count_lines_location_fk'
            );
            $this->foreign(
                $table,
                'lot_id',
                'inventory_item_lots',
                'inventory_count_lines_lot_fk'
            );
        });

        $schema->create('inventory_count_observations', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('count_line_id');
            $table->smallInteger('count_number')->unsigned();
            $table->decimal('counted_qty', 18, 6);
            $table->foreignId('counted_by')->nullable();
            $table->dateTime('counted_at');
            $table->uniqueCombo(
                ['count_line_id', 'count_number'],
                'inventory_count_observation_number_unique'
            );
            $this->foreign(
                $table,
                'count_line_id',
                'inventory_stock_count_lines',
                'inventory_count_observations_line_fk'
            );
            $this->foreign($table, 'counted_by', 'users', 'inventory_count_observations_user_fk');
        });

        $schema->create('inventory_stock_ledger_entries', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('operation_id');
            $table->foreignId('operation_line_id');
            $table->foreignId('item_id');
            $table->foreignId('location_id');
            $table->foreignId('lot_id');
            $table->foreignId('serial_id')->nullable();
            $table->string('movement_type', 20);
            $table->decimal('quantity', 18, 6);
            $table->dateTime('operational_at');
            $table->foreignId('actor_user_id')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('reversal_of_id')->nullable()->unique();
            $table->uuid('group_id');
            $table->string('idempotency_key', 190);
            $table->dateTime('created_at');
            $table->index(['item_id', 'created_at', 'id'], 'inventory_ledger_item_date_index');
            $table->index(
                ['operation_id', 'operation_line_id'],
                'inventory_ledger_document_index'
            );
            $table->index(['group_id', 'id'], 'inventory_ledger_group_index');
            $table->index(
                ['item_id', 'location_id', 'lot_id', 'id'],
                'inventory_ledger_balance_index'
            );
            $this->foreign(
                $table,
                'operation_id',
                'inventory_stock_operations',
                'inventory_ledger_operation_fk'
            );
            $this->foreign(
                $table,
                'operation_line_id',
                'inventory_operation_lines',
                'inventory_ledger_line_fk'
            );
            $this->foreign($table, 'item_id', 'inventory_items', 'inventory_ledger_item_fk');
            $this->foreign(
                $table,
                'location_id',
                'inventory_locations',
                'inventory_ledger_location_fk'
            );
            $this->foreign($table, 'lot_id', 'inventory_item_lots', 'inventory_ledger_lot_fk');
            $this->foreign(
                $table,
                'serial_id',
                'inventory_serial_numbers',
                'inventory_ledger_serial_fk'
            );
            $this->foreign($table, 'actor_user_id', 'users', 'inventory_ledger_actor_fk');
            $this->foreign(
                $table,
                'reversal_of_id',
                'inventory_stock_ledger_entries',
                'inventory_ledger_reversal_fk'
            );
        });

        $schema->create('inventory_stock_balances', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('item_id');
            $table->foreignId('location_id');
            $table->foreignId('lot_id');
            $table->decimal('physical_qty', 18, 6)->default(0);
            $table->decimal('reserved_qty', 18, 6)->default(0);
            $table->foreignId('last_entry_id')->nullable();
            $table->dateTime('updated_at');
            $table->uniqueCombo(
                ['item_id', 'location_id', 'lot_id'],
                'inventory_balances_scope_unique'
            );
            $table->index(
                ['location_id', 'item_id'],
                'inventory_balances_location_item_index'
            );
            $this->foreign($table, 'item_id', 'inventory_items', 'inventory_balances_item_fk');
            $this->foreign(
                $table,
                'location_id',
                'inventory_locations',
                'inventory_balances_location_fk'
            );
            $this->foreign($table, 'lot_id', 'inventory_item_lots', 'inventory_balances_lot_fk');
            $this->foreign(
                $table,
                'last_entry_id',
                'inventory_stock_ledger_entries',
                'inventory_balances_last_entry_fk'
            );
        });

        $schema->create('inventory_stock_reservations', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('item_id');
            $table->foreignId('warehouse_id');
            $table->decimal('quantity_reserved', 18, 6);
            $table->decimal('quantity_consumed', 18, 6)->default(0);
            $table->decimal('quantity_released', 18, 6)->default(0);
            $table->string('status', 20)->default('active');
            $table->string('reservation_key', 190)->unique();
            $table->dateTime('expires_at')->nullable();
            $table->foreignId('created_by')->nullable();
            $this->timestamps($table);
            $table->index(
                ['item_id', 'warehouse_id', 'status', 'expires_at'],
                'inventory_reservations_item_warehouse_status_index'
            );
            $this->foreign(
                $table,
                'item_id',
                'inventory_items',
                'inventory_reservations_item_fk'
            );
            $this->foreign(
                $table,
                'warehouse_id',
                'inventory_warehouses',
                'inventory_reservations_warehouse_fk'
            );
            $this->foreign($table, 'created_by', 'users', 'inventory_reservations_user_fk');
        });

        $schema->create('inventory_reservation_allocations', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('reservation_id');
            $table->foreignId('balance_id');
            $table->decimal('quantity_reserved', 18, 6);
            $table->decimal('quantity_consumed', 18, 6)->default(0);
            $table->decimal('quantity_released', 18, 6)->default(0);
            $this->timestamps($table);
            $table->uniqueCombo(
                ['reservation_id', 'balance_id'],
                'inventory_reservation_allocation_scope_unique'
            );
            $this->foreign(
                $table,
                'reservation_id',
                'inventory_stock_reservations',
                'inventory_reservation_allocation_reservation_fk'
            );
            $this->foreign(
                $table,
                'balance_id',
                'inventory_stock_balances',
                'inventory_reservation_allocation_balance_fk'
            );
        });

        $schema->create('inventory_serial_history', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('serial_id');
            $table->foreignId('ledger_entry_id');
            $table->string('previous_status', 20)->nullable();
            $table->string('new_status', 20);
            $table->foreignId('previous_location_id')->nullable();
            $table->foreignId('new_location_id')->nullable();
            $table->dateTime('created_at');
            $table->uniqueCombo(
                ['ledger_entry_id'],
                'inventory_serial_history_entry_unique'
            );
            $table->index(
                ['serial_id', 'created_at', 'id'],
                'inventory_serial_history_serial_date_index'
            );
            $this->foreign(
                $table,
                'serial_id',
                'inventory_serial_numbers',
                'inventory_serial_history_serial_fk'
            );
            $this->foreign(
                $table,
                'ledger_entry_id',
                'inventory_stock_ledger_entries',
                'inventory_serial_history_ledger_fk'
            );
            $this->foreign(
                $table,
                'previous_location_id',
                'inventory_locations',
                'inventory_serial_history_previous_location_fk'
            );
            $this->foreign(
                $table,
                'new_location_id',
                'inventory_locations',
                'inventory_serial_history_new_location_fk'
            );
        });

        $schema->create('inventory_stock_outbox', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->char('event_id', 36)->unique();
            $table->string('event_type', 120);
            $table->string('aggregate_type', 80);
            $table->string('aggregate_id', 190);
            $table->smallInteger('schema_version')->unsigned();
            $table->json('payload');
            $table->string('status', 20)->default('pending');
            $table->smallInteger('attempts')->unsigned()->default(0);
            $table->dateTime('available_at');
            $table->char('reservation_token', 36)->nullable();
            $table->dateTime('reserved_until')->nullable();
            $table->dateTime('dispatched_at')->nullable();
            $table->text('last_error')->nullable();
            $table->dateTime('created_at');
            $table->index(['status', 'available_at', 'id'], 'inventory_outbox_drain_index');
            $table->index(
                ['aggregate_type', 'aggregate_id', 'id'],
                'inventory_outbox_aggregate_index'
            );
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        foreach ([
            'inventory_stock_outbox',
            'inventory_serial_history',
            'inventory_reservation_allocations',
            'inventory_stock_reservations',
            'inventory_stock_balances',
            'inventory_stock_ledger_entries',
            'inventory_count_observations',
            'inventory_stock_count_lines',
            'inventory_stock_counts',
            'inventory_stock_transfers',
            'inventory_operation_lines',
            'inventory_stock_operations',
        ] as $table) {
            $schema->dropIfExists($table);
        }
    }
}
