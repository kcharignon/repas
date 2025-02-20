<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250220101627 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout de la colonne compatible_units avec une valeur calculée en fonction de default_cooking_unit et default_purchase_unit';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql("ALTER TABLE ingredient ADD compatible_units JSON NOT NULL DEFAULT '[]'");

        // Récupérer et mettre à jour les données existantes
        $this->addSql("UPDATE ingredient SET compatible_units =
            CASE
                WHEN default_cooking_unit = default_purchase_unit THEN jsonb_build_array(default_cooking_unit)
                ELSE jsonb_build_array(default_cooking_unit, default_purchase_unit)
            END");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE ingredient DROP compatible_units');
    }
}
