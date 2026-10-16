<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Confirmation d'adresse email à l'inscription (ADR 0020) : table des liens de confirmation. Les comptes existants restent
 * `ACTIVE` : le nouveau statut `PENDING` (colonne texte) ne concerne que les inscriptions à venir. Compatible avec la
 * version précédente du code (ADR 0017 règle 6) : elle ignore la table.
 */
final class Version20261016170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Table email_verification_token : liens de confirmation d'adresse email";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE email_verification_token (id VARCHAR(36) NOT NULL, user_id VARCHAR(36) NOT NULL, token_hash VARCHAR(64) NOT NULL, issued_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C4995C67B3BC57DA ON email_verification_token (token_hash)');
        $this->addSql('CREATE INDEX idx_email_verification_token_user ON email_verification_token (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE email_verification_token');
    }
}
