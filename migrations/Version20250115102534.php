<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250115102534 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE department (slug VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, image VARCHAR(2048) NOT NULL, PRIMARY KEY(slug))');
        $this->addSql('CREATE TABLE "ingredient" (slug VARCHAR(255) NOT NULL, department VARCHAR(255) NOT NULL, default_cooking_unit VARCHAR(255) NOT NULL, default_purchase_unit VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, PRIMARY KEY(slug))');
        $this->addSql('CREATE INDEX IDX_6BAF7870CD1DE18A ON "ingredient" (department)');
        $this->addSql('CREATE INDEX IDX_6BAF78707D5987D8 ON "ingredient" (default_cooking_unit)');
        $this->addSql('CREATE INDEX IDX_6BAF78709A295823 ON "ingredient" (default_purchase_unit)');
        $this->addSql('CREATE TABLE recipe_type (slug VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, image VARCHAR(2048) NOT NULL, sequence INT NOT NULL, PRIMARY KEY(slug))');
        $this->addSql('CREATE TABLE "unit" (slug VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, symbol VARCHAR(255) NOT NULL, PRIMARY KEY(slug))');
        $this->addSql('CREATE TABLE "user" (id VARCHAR(36) NOT NULL, email VARCHAR(255) NOT NULL, password VARCHAR(255) NOT NULL, roles JSON NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON "user" (email)');
        $this->addSql('ALTER TABLE "ingredient" ADD CONSTRAINT FK_6BAF7870CD1DE18A FOREIGN KEY (department) REFERENCES department (slug) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE "ingredient" ADD CONSTRAINT FK_6BAF78707D5987D8 FOREIGN KEY (default_cooking_unit) REFERENCES "unit" (slug) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE "ingredient" ADD CONSTRAINT FK_6BAF78709A295823 FOREIGN KEY (default_purchase_unit) REFERENCES "unit" (slug) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE "ingredient" DROP CONSTRAINT FK_6BAF7870CD1DE18A');
        $this->addSql('ALTER TABLE "ingredient" DROP CONSTRAINT FK_6BAF78707D5987D8');
        $this->addSql('ALTER TABLE "ingredient" DROP CONSTRAINT FK_6BAF78709A295823');
        $this->addSql('DROP TABLE department');
        $this->addSql('DROP TABLE "ingredient"');
        $this->addSql('DROP TABLE recipe_type');
        $this->addSql('DROP TABLE "unit"');
        $this->addSql('DROP TABLE "user"');
    }
}
