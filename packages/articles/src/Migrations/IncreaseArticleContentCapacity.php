<?php

declare(strict_types=1);

namespace NM\Services\Articles\Migrations;

use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Migration;
use Core\Database\Schema\SchemaBuilderInterface;
use Core\Database\Schema\SqliteSchemaBuilder;

final class IncreaseArticleContentCapacity extends Migration
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema instanceof SqliteSchemaBuilder) {
            return;
        }

        if (!$schema->hasTable('articles') || !$schema->hasColumn('articles', 'content')) {
            return;
        }

        $schema->alter('articles', modify: static function (Blueprint $table): void {
            $table->mediumText('content')->nullable();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        // Keep the larger type on rollback rather than risk truncating saved content.
    }
}
