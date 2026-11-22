<?php

declare(strict_types=1);

namespace NM\Services\Articles\Migrations;

use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Migration;
use Core\Database\Schema\SchemaBuilderInterface;

final class CreateArticleTables extends Migration
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('article_categories', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->string('name', 190);
            $table->string('slug', 190)->unique();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active');
            $this->timestamps($table);
            $table->index(['status', 'name'], 'article_categories_status_name_index');
        });

        $schema->create('articles', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('author_id')->nullable();
            $table->foreignId('primary_category_id')->nullable();
            $table->string('title', 190);
            $table->string('slug', 220)->unique();
            $table->text('excerpt')->nullable();
            $table->mediumText('content')->nullable();
            $table->string('featured_image', 255)->nullable();
            $table->string('status', 20)->default('draft');
            $table->dateTime('published_at')->nullable();
            $table->dateTime('archived_at')->nullable();
            $this->timestamps($table);
            $table->index(['status', 'published_at'], 'articles_status_published_index');
            $table->index(['status', 'updated_at'], 'articles_status_updated_index');
            $table->foreign('author_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('primary_category_id')->references('id')->on('article_categories')->nullOnDelete();
        });

        $schema->create('article_article_category', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('article_id');
            $table->foreignId('article_category_id');
            $this->timestamps($table);
            $table->uniqueCombo(['article_id', 'article_category_id'], 'article_article_category_unique');
            $table->foreign('article_id')->references('id')->on('articles')->cascadeOnDelete();
            $table->foreign('article_category_id')->references('id')->on('article_categories')->cascadeOnDelete();
        });

        $schema->create('article_views', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('article_id');
            $table->foreignId('user_id')->nullable();
            $table->string('session_key', 128)->nullable();
            $table->string('fingerprint', 128)->nullable();
            $table->unsignedInteger('view_count')->default(1);
            $table->dateTime('last_viewed_at')->nullable();
            $this->timestamps($table);
            $table->index(['article_id', 'last_viewed_at'], 'article_views_article_seen_index');
            $table->index(['fingerprint', 'last_viewed_at'], 'article_views_fingerprint_index');
            $table->foreign('article_id')->references('id')->on('articles')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        foreach (['article_views', 'article_article_category', 'articles', 'article_categories'] as $table) {
            $schema->dropIfExists($table);
        }
    }

    private function timestamps(Blueprint $table): void
    {
        $table->dateTime('created_at')->nullable();
        $table->dateTime('updated_at')->nullable();
    }
}
