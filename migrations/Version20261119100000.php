<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le journal des listes de courses (ADR 0041, demande du propriétaire : analyser des comportements étranges) :
 *
 * - `shopping_list_row.checked_by` (`MANUAL` | `DRIVE`) et `checked_at` : qui a coché la ligne et quand, vides tant qu'elle n'est pas cochée et pour une ligne
 *   cochée avant cette migration (origine inconnue, jamais devinée) ;
 * - `shopping_list_row.drive_outcome`, `drive_reason`, `drive_attempted_at` : ce que le DERNIER passage au drive a fait de la ligne (`IN_CART`, `NOT_IN_CART`,
 *   `UNAVAILABLE`, `SKIPPED`, `HAVE_AT_HOME`, `FAILED`), la raison quand l'agent la connaît (un code) et l'instant du passage, vides pour une ligne jamais
 *   passée au drive ;
 * - la table `shopping_list_archive` : la copie à plat d'une liste supprimée (la liste, ses lignes et ses repas avec les noms d'ingrédient, d'unité, de recette
 *   et de type de liste, en JSONB), sans clé étrangère ni jointure à faire ; `owner_id` est effacé avec le compte (`PurgeUserData`).
 *
 * Additive et seulement additive (ADR 0017 règle 6) : cinq colonnes vides et une table vide que l'entité du code précédent ne mappe pas (ses `INSERT` les
 * laissent vides, ses `UPDATE` n'y touchent pas) ; aucune donnée existante n'est touchée, le retour arrière du code ne défait pas le schéma. Rejouable sans
 * erreur (`IF NOT EXISTS`). `down` supprime les colonnes (donc l'origine des coches et l'issue du drive de chaque ligne) puis la table et toutes ses archives :
 * il ne se joue qu'à la main, sur décision humaine.
 */
final class Version20261119100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Journal des listes de courses : origine des coches, issue du drive par ligne et table shopping_list_archive';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shopping_list_row ADD COLUMN IF NOT EXISTS checked_by VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE shopping_list_row ADD COLUMN IF NOT EXISTS checked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE shopping_list_row ADD COLUMN IF NOT EXISTS drive_outcome VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE shopping_list_row ADD COLUMN IF NOT EXISTS drive_reason VARCHAR(40) DEFAULT NULL');
        $this->addSql('ALTER TABLE shopping_list_row ADD COLUMN IF NOT EXISTS drive_attempted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

        $this->addSql('CREATE TABLE IF NOT EXISTS shopping_list_archive (id VARCHAR(36) NOT NULL, shopping_list_id VARCHAR(255) NOT NULL, owner_id VARCHAR(255) NOT NULL, name VARCHAR(255) DEFAULT NULL, status VARCHAR(16) NOT NULL, list_type_id VARCHAR(36) DEFAULT NULL, list_type_name VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, version INT NOT NULL, archived_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, cause VARCHAR(32) NOT NULL, line_count INT NOT NULL, lines JSONB NOT NULL, meals JSONB NOT NULL, part_shares JSONB DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_shopping_list_archive_owner ON shopping_list_archive (owner_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_shopping_list_archive_list ON shopping_list_archive (shopping_list_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_shopping_list_archive_list');
        $this->addSql('DROP INDEX IF EXISTS idx_shopping_list_archive_owner');
        $this->addSql('DROP TABLE IF EXISTS shopping_list_archive');

        $this->addSql('ALTER TABLE shopping_list_row DROP COLUMN IF EXISTS drive_attempted_at');
        $this->addSql('ALTER TABLE shopping_list_row DROP COLUMN IF EXISTS drive_reason');
        $this->addSql('ALTER TABLE shopping_list_row DROP COLUMN IF EXISTS drive_outcome');
        $this->addSql('ALTER TABLE shopping_list_row DROP COLUMN IF EXISTS checked_at');
        $this->addSql('ALTER TABLE shopping_list_row DROP COLUMN IF EXISTS checked_by');
    }
}
