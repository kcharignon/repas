<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Chantier « liste envoyée au drive » (DRV-204) : produits connus de l'enseigne (faits publics, prix daté compris) et
 * préférences privées de chaque utilisateur, avec leurs produits acceptés (classés) et refusés. Nouvelles tables, sans clé
 * étrangère : compatible avec la version précédente du code (ADR 0017 règle 6), qui les ignore.
 */
final class Version20261018110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tables drive_product, drive_choice et drive_choice_product : produits du drive et préférences des utilisateurs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE drive_product (id VARCHAR(36) NOT NULL, retailer VARCHAR(20) NOT NULL, store_id VARCHAR(20) NOT NULL, retailer_product_id VARCHAR(40) NOT NULL, ean VARCHAR(14) DEFAULT NULL, label VARCHAR(255) NOT NULL, content_value NUMERIC(10, 3) DEFAULT NULL, content_unit VARCHAR(8) DEFAULT NULL, price_cents INT DEFAULT NULL, price_per_measure_cents INT DEFAULT NULL, price_per_measure_unit VARCHAR(8) DEFAULT NULL, price_seen_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_drive_product_ean ON drive_product (ean)');
        $this->addSql('CREATE UNIQUE INDEX uniq_drive_product_key ON drive_product (retailer, store_id, retailer_product_id)');
        $this->addSql('CREATE TABLE drive_choice (id VARCHAR(36) NOT NULL, user_id VARCHAR(36) NOT NULL, retailer VARCHAR(20) NOT NULL, store_id VARCHAR(20) NOT NULL, ingredient_slug VARCHAR(255) NOT NULL, kind VARCHAR(16) NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_drive_choice_user_store_ingredient ON drive_choice (user_id, retailer, store_id, ingredient_slug)');
        $this->addSql('CREATE TABLE drive_choice_product (choice_id VARCHAR(36) NOT NULL, product_id VARCHAR(36) NOT NULL, verdict VARCHAR(8) NOT NULL, rank SMALLINT DEFAULT NULL, PRIMARY KEY (choice_id, product_id))');
        $this->addSql('CREATE INDEX idx_drive_choice_product_product ON drive_choice_product (product_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_drive_choice_product_rank ON drive_choice_product (choice_id, rank)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE drive_choice_product');
        $this->addSql('DROP TABLE drive_choice');
        $this->addSql('DROP TABLE drive_product');
    }
}
