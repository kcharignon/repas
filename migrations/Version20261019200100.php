<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Chercher dans le bon rayon (DRV-642, lot 1a) : ce que Repas apprend des choix de produits, par enseigne : pour un rayon de Repas
 * (`department_slug`), dans quel rayon de l'enseigne (`rayon_id`) les produits sont choisis, et combien de fois. Nouvelle table, sans
 * clé étrangère et sans colonne qui désigne un utilisateur ou un magasin (des faits de catalogue agrégés : rien à purger avec un
 * compte) : compatible avec la version précédente du code (ADR 0017 règle 6), qui l'ignore. Aucune donnée de départ.
 */
final class Version20261019200100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Table drive_department_rayon : rayons de l\'enseigne appris des choix, par rayon de Repas';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE drive_department_rayon (retailer VARCHAR(20) NOT NULL, department_slug VARCHAR(255) NOT NULL, rayon_id INT NOT NULL, chosen_count INT NOT NULL, PRIMARY KEY (retailer, department_slug, rayon_id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE drive_department_rayon');
    }
}
