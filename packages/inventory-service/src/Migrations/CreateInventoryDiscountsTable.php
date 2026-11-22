<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Migrations;

use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Migration;
use Core\Database\Schema\SchemaBuilderInterface;

final class CreateInventoryDiscountsTable extends Migration
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('discounts', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->string('target_type', 100);
            $table->foreignId('target_id');
            $table->string('discount_type', 20);
            $table->decimal('value', 12, 2);
            $table->char('currency', 3)->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->index(['target_type', 'target_id'], 'discounts_target_index');
            $table->index(
                ['target_type', 'target_id', 'is_active', 'starts_at', 'ends_at'],
                'discounts_period_lookup_index'
            );
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('discounts');
    }
}
