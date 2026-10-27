<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rayons appris par compte (DRV-642) : un vote par compte et par couple (enseigne, rayon de Repas, rayon de l'enseigne). Le plan classe les
 * rayons proposés par nombre de comptes distincts, au lieu de compter chaque envoi. Nouvelle table, sans clé étrangère : le compte
 * (`user_id`) est effacé avec lui (ADR 0021). Compatible avec la version précédente du code (ADR 0017 règle 6), qui l'ignore : l'ancienne
 * table `drive_department_rayon` reste en place, intacte, et le retour arrière du code la retrouve telle qu'elle était. Les votes de départ
 * sont écrits par la migration suivante (`Version20261027180100`). Rejouable sans erreur.
 */
final class Version20261027180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Table drive_rayon_vote : un vote par compte pour un rayon de l\'enseigne, par rayon de Repas';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS drive_rayon_vote (user_id VARCHAR(36) NOT NULL, retailer VARCHAR(20) NOT NULL, department_slug VARCHAR(255) NOT NULL, rayon_id INT NOT NULL, PRIMARY KEY (user_id, retailer, department_slug, rayon_id))');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_drive_rayon_vote_department ON drive_rayon_vote (retailer, department_slug, rayon_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS drive_rayon_vote');
    }
}
