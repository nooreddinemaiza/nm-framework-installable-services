<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Migrations;

use Core\Database\Schema\Blueprint;
use Core\Database\Schema\ForeignKeyDefinition;
use Core\Database\Schema\Migration;

abstract class CatalogMigration extends Migration
{
    protected function timestamps(Blueprint $table): void
    {
        $table->dateTime('created_at');
        $table->dateTime('updated_at');
    }

    protected function foreign(
        Blueprint $table,
        string $column,
        string $target,
        string $name,
    ): ForeignKeyDefinition {
        return $table->foreign($column)
            ->references('id')
            ->on($target)
            ->restrictOnDelete()
            ->name($name);
    }
}
