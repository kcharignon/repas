<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Chercher dans le bon rayon (DRV-642, lot 1a) : un produit du drive retient le rayon et la famille que l'enseigne lui donne (faits
 * du catalogue rapportés par l'agent). Deux colonnes nullables : compatible avec la version précédente du code (ADR 0017
 * règle 6), qui ne les écrit ni ne les lit et laisse `NULL`. Le retour arrière du code ne défait pas le schéma.
 */
final class Version20261019200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Colonnes drive_product.rayon_id et family_id : rayon et famille de l\'enseigne pour un produit';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE drive_product ADD rayon_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE drive_product ADD family_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE drive_product DROP rayon_id');
        $this->addSql('ALTER TABLE drive_product DROP family_id');
    }
}
