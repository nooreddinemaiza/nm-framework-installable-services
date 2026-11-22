<?php

declare(strict_types=1);

namespace NM\Services\GlobalSearch\Migrations;

use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Migration;
use Core\Database\Schema\SchemaBuilderInterface;

final class CreateGlobalSearchSettings extends Migration
{
    private const OWNERSHIP_TABLE = 'global_search_service_migration_ownership';

    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('global_search_settings')) {
            foreach (['id', 'key', 'value', 'created_at', 'updated_at'] as $column) {
                if (!$schema->hasColumn('global_search_settings', $column)) {
                    throw new \RuntimeException(
                        'La table global_search_settings existe, mais son schéma est incompatible.'
                    );
                }
            }

            return;
        }

        $schema->create('global_search_settings', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->string('key', 80)->unique();
            $table->text('value');
            $table->timestamps();
        });
        $schema->create(self::OWNERSHIP_TABLE, static function (Blueprint $table): void {
            $table->id()->unsigned();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable(self::OWNERSHIP_TABLE)) {
            return;
        }

        $schema->dropIfExists('global_search_settings');
        $schema->drop(self::OWNERSHIP_TABLE);
    }
}
