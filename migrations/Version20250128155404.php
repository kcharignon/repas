<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250128155404 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE conversion (slug VARCHAR(767) NOT NULL, coefficient DOUBLE PRECISION NOT NULL, ingredient VARCHAR(255) DEFAULT NULL, PRIMARY KEY(slug))');
        $this->addSql('CREATE TABLE department (slug VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, image VARCHAR(2048) NOT NULL, PRIMARY KEY(slug))');
        $this->addSql('CREATE TABLE ingredient (slug VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, image VARCHAR(2048) NOT NULL, department VARCHAR(255) NOT NULL, default_cooking_unit VARCHAR(255) NOT NULL, default_purchase_unit VARCHAR(255) NOT NULL, PRIMARY KEY(slug))');
        $this->addSql('CREATE TABLE meal (id VARCHAR(255) NOT NULL, shopping_list VARCHAR(255) NOT NULL, recipe VARCHAR(36) NOT NULL, serving INT NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_9EF68E9C3DC1A459 ON meal (shopping_list)');
        $this->addSql('CREATE INDEX IDX_9EF68E9CDA88B137 ON meal (recipe)');
        $this->addSql('CREATE TABLE recipe (id VARCHAR(36) NOT NULL, name VARCHAR(255) NOT NULL, serving INT NOT NULL, author VARCHAR(255) NOT NULL, type VARCHAR(255) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE TABLE recipe_row (id VARCHAR(36) NOT NULL, ingredient VARCHAR(255) NOT NULL, quantity DOUBLE PRECISION NOT NULL, unit VARCHAR(255) NOT NULL, recipe VARCHAR(255) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE TABLE recipe_type (slug VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, image VARCHAR(2048) NOT NULL, sequence INT NOT NULL, PRIMARY KEY(slug))');
        $this->addSql('CREATE TABLE shopping_list (id VARCHAR(255) NOT NULL, owner_id VARCHAR(36) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, locked BOOLEAN NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_3DC1A4597E3C61F9 ON shopping_list (owner_id)');
        $this->addSql('COMMENT ON COLUMN shopping_list.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE unit (slug VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, symbol VARCHAR(255) NOT NULL, PRIMARY KEY(slug))');
        $this->addSql('CREATE TABLE "user" (id VARCHAR(36) NOT NULL, email VARCHAR(255) NOT NULL, password VARCHAR(255) NOT NULL, roles JSON NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON "user" (email)');
        $this->addSql('ALTER TABLE meal ADD CONSTRAINT FK_9EF68E9C3DC1A459 FOREIGN KEY (shopping_list) REFERENCES shopping_list (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE meal ADD CONSTRAINT FK_9EF68E9CDA88B137 FOREIGN KEY (recipe) REFERENCES recipe (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE shopping_list ADD CONSTRAINT FK_3DC1A4597E3C61F9 FOREIGN KEY (owner_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE meal DROP CONSTRAINT FK_9EF68E9C3DC1A459');
        $this->addSql('ALTER TABLE meal DROP CONSTRAINT FK_9EF68E9CDA88B137');
        $this->addSql('ALTER TABLE shopping_list DROP CONSTRAINT FK_3DC1A4597E3C61F9');
        $this->addSql('DROP TABLE conversion');
        $this->addSql('DROP TABLE department');
        $this->addSql('DROP TABLE ingredient');
        $this->addSql('DROP TABLE meal');
        $this->addSql('DROP TABLE recipe');
        $this->addSql('DROP TABLE recipe_row');
        $this->addSql('DROP TABLE recipe_type');
        $this->addSql('DROP TABLE shopping_list');
        $this->addSql('DROP TABLE unit');
        $this->addSql('DROP TABLE "user"');
    }
}
