<?php

declare(strict_types=1);

namespace NM\Services\SocialAuth\Migrations;

use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Migration;
use Core\Database\Schema\SchemaBuilderInterface;

/**
 * Authentification sociale : associations identité externe → utilisateur.
 * Aucune colonne propre à un fournisseur n'est ajoutée à `users`.
 */
final class CreateSocialIdentitiesTable extends Migration
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('social_identities', function (Blueprint $table): void {
            $table->id()->unsigned();

            $table->foreignId('user_id');

            // Fournisseur (ex. google) + identifiant stable du compte chez lui (jamais l'e-mail).
            $table->string('provider', 32);
            $table->string('provider_user_id', 191);
            // SHA-256 du sujet : unicité exacte même sous une collation insensible à la casse.
            $table->char('subject_hash', 64);

            // Vrai si cette identité a servi à créer le compte (règle « dernier moyen de connexion »).
            $table->boolean('created_account')->default(false);
            $table->dateTime('last_login_at')->nullable();

            $table->timestamps();

            $table->uniqueCombo(['provider', 'provider_user_id'], 'social_identities_provider_subject_unique');
            $table->uniqueCombo(['provider', 'subject_hash'], 'social_identities_provider_hash_unique');
            // Une seule identité par fournisseur et par utilisateur.
            $table->uniqueCombo(['user_id', 'provider'], 'social_identities_user_provider_unique');

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('social_identities');
    }
}
