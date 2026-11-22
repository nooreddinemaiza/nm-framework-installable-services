<?php

declare(strict_types=1);

namespace NM\Services\PageManagement\Migrations;

use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Migration;
use Core\Database\Schema\SchemaBuilderInterface;

final class CreatePageManagementTables extends Migration
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('pages', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->string('title', 190);
            $table->string('slug', 220)->unique();
            $table->text('description')->nullable();
            $table->string('featured_image', 255)->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('visibility', 20)->default('public');
            $table->foreignId('parent_id')->nullable();
            $table->string('layout', 100)->default('default');
            $table->dateTime('published_at')->nullable();
            $table->foreignId('author_id')->nullable();
            $table->text('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->string('seo_image', 255)->nullable();
            $table->string('canonical_url', 2048)->nullable();
            $table->boolean('no_index')->default(false);
            $table->boolean('is_homepage')->default(false);
            $table->string('homepage_key', 20)->nullable()->unique();
            $table->boolean('show_header')->default(true);
            $table->boolean('show_footer')->default(true);
            $table->boolean('show_announcement_bar')->default(true);
            $table->boolean('add_to_navigation')->default(false);
            $table->string('navigation_label', 190)->nullable();
            $table->integer('navigation_order')->default(0);
            $table->integer('revision')->default(1);
            $table->timestamps();
            $table->index(['status', 'published_at'], 'pages_status_published_index');
            $table->index(['parent_id', 'status'], 'pages_parent_status_index');
            $table->foreign('parent_id')->references('id')->on('pages')->nullOnDelete();
            $table->foreign('author_id')->references('id')->on('users')->nullOnDelete();
        });

        $schema->create('page_slug_redirects', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->string('slug', 220)->unique();
            $table->foreignId('page_id');
            $table->timestamps();
            $table->foreign('page_id')->references('id')->on('pages')->cascadeOnDelete();
        });

        $schema->create('page_templates', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->string('name', 190);
            $table->string('description', 1000)->nullable();
            $table->string('category', 100)->nullable();
            $table->string('origin', 20)->default('custom');
            $table->string('developer_key', 190)->nullable()->unique();
            $table->json('structure');
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->index(['origin', 'category'], 'page_templates_origin_category_index');
        });

        $schema->create('page_sections', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->string('name', 190);
            $table->string('type', 100);
            $table->json('settings')->nullable();
            $table->boolean('in_library')->default(false);
            $table->foreignId('owner_page_id')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->timestamps();
            $table->index(['in_library', 'type'], 'page_sections_library_type_index');
            $table->foreign('owner_page_id')->references('id')->on('pages')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        $schema->create('page_section_usages', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('page_id');
            $table->foreignId('section_id');
            $table->string('usage_mode', 20)->default('copy');
            $table->string('slot', 100)->default('content');
            $table->integer('sort_order')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->uniqueCombo(['page_id', 'section_id'], 'page_section_usage_unique');
            $table->index(['page_id', 'slot', 'sort_order'], 'page_section_usage_order_index');
            $table->foreign('page_id')->references('id')->on('pages')->cascadeOnDelete();
            $table->foreign('section_id')->references('id')->on('page_sections')->restrictOnDelete();
        });

        $schema->create('page_blocks', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('section_id');
            $table->string('type', 100);
            $table->json('configuration');
            $table->integer('sort_order')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->index(['section_id', 'sort_order'], 'page_blocks_order_index');
            $table->foreign('section_id')->references('id')->on('page_sections')->cascadeOnDelete();
        });

        $schema->create('page_menus', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->string('name', 190);
            $table->string('slug', 190)->unique();
            $table->string('location', 100)->nullable();
            $table->timestamps();
        });

        $schema->create('page_menu_items', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('menu_id');
            $table->foreignId('parent_id')->nullable();
            $table->foreignId('page_id')->nullable();
            $table->string('label', 190);
            $table->string('type', 20)->default('custom');
            $table->string('destination', 2048)->nullable();
            $table->string('icon', 100)->nullable();
            $table->string('target', 20)->default('_self');
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['menu_id', 'parent_id', 'sort_order'], 'page_menu_items_order_index');
            $table->foreign('menu_id')->references('id')->on('page_menus')->cascadeOnDelete();
            $table->foreign('parent_id')->references('id')->on('page_menu_items')->nullOnDelete();
            $table->foreign('page_id')->references('id')->on('pages')->nullOnDelete();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        foreach ([
            'page_menu_items',
            'page_menus',
            'page_blocks',
            'page_section_usages',
            'page_sections',
            'page_templates',
            'page_slug_redirects',
            'pages',
        ] as $table) {
            $schema->dropIfExists($table);
        }
    }
}
