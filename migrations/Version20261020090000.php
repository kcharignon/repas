<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Connexion avec Google (ADR 0024 décision 8, AUTH-005) : table des liens entre un compte et une identité externe. La clé est
 * `(provider, subject)`, le `sub` du jeton (jamais l'adresse, qui n'est pas conservée ici) ; un compte a au plus un lien par
 * fournisseur, `(user_id, provider)`. Aucune clé étrangère (convention du dépôt, ADR 0021) : la suppression du compte efface ses
 * liens (`DeleteUserHandler`).
 *
 * Additive : nouvelle table seulement, aucune colonne ajoutée ni modifiée ailleurs (`user` est intact). Compatible avec la
 * version précédente du code (ADR 0017 règle 6), qui ne connaît pas la table et l'ignore ; le retour arrière du code ne défait
 * pas le schéma.
 *
 * `down` supprime la table et donc tous les liens, sans toucher aux comptes. Un compte créé par Google n'a pas de mot de passe :
 * sans son lien, il ne se reconnecte que par une nouvelle liaison (adresse Gmail) ou par « mot de passe oublié ». Le retour
 * arrière du code n'en a pas besoin (la table est ignorée) : `down` ne se joue qu'à la main, sur décision humaine.
 */
final class Version20261020090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Table user_identity : liens entre un compte et une identité externe (connexion avec Google)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_identity (id VARCHAR(36) NOT NULL, user_id VARCHAR(36) NOT NULL, provider VARCHAR(32) NOT NULL, subject VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_identity_provider_subject ON user_identity (provider, subject)');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_identity_user_provider ON user_identity (user_id, provider)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_identity');
    }
}
