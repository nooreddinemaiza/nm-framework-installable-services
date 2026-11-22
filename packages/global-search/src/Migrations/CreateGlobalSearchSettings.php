<?php

declare(strict_types=1);

namespace NM\Services\GlobalSearch\Migrations;

use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Migration;
use Core\Database\Schema\SchemaBuilderInterface;

final class CreateGlobalSearchSettings extends Migration
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('global_search_settings', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->string('key', 80)->unique();
            $table->text('value');
            $table->timestamps();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('global_search_settings');
    }
}
