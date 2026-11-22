<?php

declare(strict_types=1);

namespace NM\Services\SalesService\Migrations;

use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Migration;
use Core\Database\Schema\SchemaBuilderInterface;
use Core\Database\Schema\SqliteSchemaBuilder;

final class AddSalesOrderPublicReference extends Migration
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('sales_orders')) {
            return;
        }
        if (!$schema->hasColumn('sales_orders', 'public_reference')) {
            $schema->alter('sales_orders', add: static function (Blueprint $table): void {
                $table->string('public_reference', 20)->nullable()->unique(true, 'sales_orders_public_reference_code_unique');
            });
        }
        if (!$schema->hasColumn('sales_orders', 'public_reference_hash')) {
            $schema->alter('sales_orders', add: static function (Blueprint $table): void {
                $table->char('public_reference_hash', 64)->nullable()->unique(true, 'sales_orders_public_reference_unique');
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        if ($schema instanceof SqliteSchemaBuilder) {
            // SQLite cannot drop columns that carry inline UNIQUE constraints.
            return;
        }

        if (!$schema->hasTable('sales_orders')) {
            return;
        }
        $columns = array_values(array_filter(
            ['public_reference_hash', 'public_reference'],
            static fn(string $column): bool => $schema->hasColumn('sales_orders', $column)
        ));
        if ($columns !== []) {
            $schema->alter('sales_orders', drop: static function (Blueprint $table) use ($columns): void {
                if (in_array('public_reference_hash', $columns, true)) {
                    $table->dropUnique('sales_orders_public_reference_unique');
                }
                if (in_array('public_reference', $columns, true)) {
                    $table->dropUnique('sales_orders_public_reference_code_unique');
                }
                $table->dropColumn(...$columns);
            });
        }
    }
}
