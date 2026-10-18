<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Chantier « liste envoyée au drive » (DRV-203) : réglages du drive de l'utilisateur, un magasin par enseigne. Nouvelle table,
 * sans clé étrangère : compatible avec la version précédente du code (ADR 0017 règle 6), qui l'ignore.
 */
final class Version20261018100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Table drive_settings : magasin du drive de chaque utilisateur, par enseigne';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE drive_settings (user_id VARCHAR(36) NOT NULL, retailer VARCHAR(20) NOT NULL, store_id VARCHAR(20) NOT NULL, store_label VARCHAR(255) DEFAULT NULL, base_url VARCHAR(255) NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (user_id, retailer))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE drive_settings');
    }
}
