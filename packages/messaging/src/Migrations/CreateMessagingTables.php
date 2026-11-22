<?php

declare(strict_types=1);

namespace NM\Services\Messaging\Migrations;

use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Migration;
use Core\Database\Schema\SchemaBuilderInterface;

/**
 * Messagerie : conversations (privées / de groupe), participants, messages,
 * accusés de lecture et politiques (par rôle et globales).
 *
 * Structure finale cumulée de CreateConversationsTable, CreateMessagesTable,
 * AddConversationRuntime et AddConversationMediaSettings.
 */
final class CreateMessagingTables extends Migration
{
    public function up(SchemaBuilderInterface $schema): void
    {
        // ── conversations ───────────────────────────────────────────────────
        $schema->create('conversations', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->string('subject')->nullable();
            $table->boolean('is_group')->default(false);
            $table->foreignId('created_by')->nullable()->index();
            $table->timestamps();
            $table->foreignId('group_id')->nullable()->index();
            $table->string('direct_key', 80)->nullable()->unique();
            $table->string('status', 20)->default('active')->index();
            $table->unsignedInteger('last_message_id')->nullable();
            $table->unsignedInteger('message_limit')->default(100);
            $table->unsignedInteger('lifetime_minutes')->default(10080);
            $table->unsignedInteger('image_streak_limit')->default(3);
            $table->unsignedInteger('message_count')->default(0);
            $table->unsignedInteger('image_streak')->default(0);
            $table->dateTime('expires_at')->nullable()->index();
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('preserved_at')->nullable();
            $table->unsignedInteger('media_retention_minutes')->default(1440);

            // Purge / expiration des conversations.
            $table->index(['status', 'expires_at'], 'conversations_status_expires_index');
            // Liste des conversations d'un groupe.
            $table->index(['group_id', 'status'], 'conversations_group_status_index');
            $table->index(['last_message_id'], 'conversations_last_message_id_index');

            // Créateur supprimé : la conversation reste pour les autres participants.
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            // Groupe supprimé : ses conversations disparaissent avec lui.
            $table->foreign('group_id')->references('id')->on('groups')->cascadeOnDelete();
        });

        // ── conversation_user ───────────────────────────────────────────────
        $schema->create('conversation_user', function (Blueprint $table): void {
            $table->id()->unsigned();

            $table->foreignId('conversation_id')->index();
            $table->foreignId('user_id')->index();

            $table->dateTime('joined_at');
            $table->dateTime('left_at')->nullable();
            $table->dateTime('last_read_at')->nullable();

            $table->timestamps();

            $table->uniqueCombo(['conversation_id', 'user_id']);
            // Conversations actives d'un utilisateur.
            $table->index(['user_id', 'left_at'], 'conversation_user_user_left_index');

            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // ── messages ────────────────────────────────────────────────────────
        $schema->create('messages', function (Blueprint $table): void {
            $table->id()->unsigned();

            $table->foreignId('conversation_id')->index();
            $table->foreignId('sender_id')->index();

            $table->text('body');

            $table->dateTime('edited_at')->nullable();
            $table->string('message_type', 20)->default('text');
            $table->string('media_path', 255)->nullable();
            $table->string('media_mime_type', 50)->nullable();
            $table->unsignedInteger('media_size')->nullable();
            $table->boolean('media_preserved')->default(false);
            $table->dateTime('media_deleted_at')->nullable();
            $table->dateTime('media_expires_at')->nullable()->index();

            $table->timestamps();

            // Pagination d'un fil + comptage des non-lus.
            $table->index(['conversation_id', 'created_at'], 'messages_conversation_created_index');
            $table->index(['sender_id', 'created_at'], 'messages_sender_created_index');
            // Purge des médias expirés.
            $table->index(['media_preserved', 'media_expires_at'], 'messages_media_purge_index');

            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            $table->foreign('sender_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // ── conversation_message_receipts ───────────────────────────────────
        $schema->create('conversation_message_receipts', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('message_id')->index();
            $table->foreignId('user_id')->index();
            $table->dateTime('seen_at')->nullable();
            $table->timestamps();
            $table->uniqueCombo(['message_id', 'user_id']);
            $table->index(['user_id', 'seen_at'], 'cmr_user_seen_index');

            $table->foreign('message_id')->references('id')->on('messages')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // ── conversation_role_policies ──────────────────────────────────────
        // sr_id / rr_id : couple de rôles (émetteur / destinataire).
        $schema->create('conversation_role_policies', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->foreignId('sr_id')->index();
            $table->foreignId('rr_id')->index();
            $table->unsignedInteger('message_limit')->default(100);
            $table->unsignedInteger('lifetime_minutes')->default(10080);
            $table->unsignedInteger('image_streak_limit')->default(3);
            $table->timestamps();
            $table->uniqueCombo(['sr_id', 'rr_id']);

            $table->foreign('sr_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign('rr_id')->references('id')->on('roles')->cascadeOnDelete();
        });

        // ── conversation_group_policies ─────────────────────────────────────
        $schema->create('conversation_group_policies', function (Blueprint $table): void {
            $table->id()->unsigned();
            $table->unsignedInteger('message_limit')->default(500);
            $table->unsignedInteger('lifetime_minutes')->default(43200);
            $table->unsignedInteger('image_streak_limit')->default(3);
            $table->unsignedInteger('direct_message_limit')->default(100);
            $table->unsignedInteger('direct_lifetime_minutes')->default(10080);
            $table->unsignedInteger('direct_image_streak_limit')->default(3);
            $table->unsignedInteger('media_retention_minutes')->default(1440);
            $table->string('default_media_path', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('conversation_group_policies');
        $schema->dropIfExists('conversation_role_policies');
        $schema->dropIfExists('conversation_message_receipts');
        $schema->dropIfExists('messages');
        $schema->dropIfExists('conversation_user');
        $schema->dropIfExists('conversations');
    }
}
