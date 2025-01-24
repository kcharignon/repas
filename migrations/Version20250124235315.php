<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250124235315 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE conversion (slug VARCHAR(767) NOT NULL, start_unit VARCHAR(255) NOT NULL, end_unit VARCHAR(255) NOT NULL, ingredient VARCHAR(255) DEFAULT NULL, coefficient DOUBLE PRECISION NOT NULL, PRIMARY KEY(slug))');
        $this->addSql('CREATE INDEX IDX_BD912744E3026191 ON conversion (start_unit)');
        $this->addSql('CREATE INDEX IDX_BD912744F2798017 ON conversion (end_unit)');
        $this->addSql('CREATE INDEX IDX_BD9127446BAF7870 ON conversion (ingredient)');
        $this->addSql('CREATE TABLE department (slug VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, image VARCHAR(2048) NOT NULL, PRIMARY KEY(slug))');
        $this->addSql('CREATE TABLE ingredient (slug VARCHAR(255) NOT NULL, department VARCHAR(255) NOT NULL, default_cooking_unit VARCHAR(255) NOT NULL, default_purchase_unit VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, image VARCHAR(2048) NOT NULL, PRIMARY KEY(slug))');
        $this->addSql('CREATE INDEX IDX_6BAF7870CD1DE18A ON ingredient (department)');
        $this->addSql('CREATE INDEX IDX_6BAF78707D5987D8 ON ingredient (default_cooking_unit)');
        $this->addSql('CREATE INDEX IDX_6BAF78709A295823 ON ingredient (default_purchase_unit)');
        $this->addSql('CREATE TABLE meal (id VARCHAR(255) NOT NULL, shopping_list VARCHAR(255) NOT NULL, recipe VARCHAR(36) NOT NULL, serving INT NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_9EF68E9C3DC1A459 ON meal (shopping_list)');
        $this->addSql('CREATE INDEX IDX_9EF68E9CDA88B137 ON meal (recipe)');
        $this->addSql('CREATE TABLE recipe (id VARCHAR(36) NOT NULL, author VARCHAR(36) NOT NULL, type VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, serving INT NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_DA88B137BDAFD8C8 ON recipe (author)');
        $this->addSql('CREATE INDEX IDX_DA88B1378CDE5729 ON recipe (type)');
        $this->addSql('CREATE TABLE recipe_row (id VARCHAR(36) NOT NULL, ingredient VARCHAR(255) NOT NULL, unit VARCHAR(255) NOT NULL, recipe VARCHAR(36) NOT NULL, quantity DOUBLE PRECISION NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_F56792976BAF7870 ON recipe_row (ingredient)');
        $this->addSql('CREATE INDEX IDX_F5679297DCBB0C53 ON recipe_row (unit)');
        $this->addSql('CREATE INDEX IDX_F5679297DA88B137 ON recipe_row (recipe)');
        $this->addSql('CREATE TABLE recipe_type (slug VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, image VARCHAR(2048) NOT NULL, sequence INT NOT NULL, PRIMARY KEY(slug))');
        $this->addSql('CREATE TABLE shopping_list (id VARCHAR(255) NOT NULL, owner_id VARCHAR(36) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, locked BOOLEAN NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_3DC1A4597E3C61F9 ON shopping_list (owner_id)');
        $this->addSql('COMMENT ON COLUMN shopping_list.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE unit (slug VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, symbol VARCHAR(255) NOT NULL, PRIMARY KEY(slug))');
        $this->addSql('CREATE TABLE "user" (id VARCHAR(36) NOT NULL, email VARCHAR(255) NOT NULL, password VARCHAR(255) NOT NULL, roles JSON NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON "user" (email)');
        $this->addSql('ALTER TABLE conversion ADD CONSTRAINT FK_BD912744E3026191 FOREIGN KEY (start_unit) REFERENCES unit (slug) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE conversion ADD CONSTRAINT FK_BD912744F2798017 FOREIGN KEY (end_unit) REFERENCES unit (slug) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE conversion ADD CONSTRAINT FK_BD9127446BAF7870 FOREIGN KEY (ingredient) REFERENCES ingredient (slug) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE ingredient ADD CONSTRAINT FK_6BAF7870CD1DE18A FOREIGN KEY (department) REFERENCES department (slug) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE ingredient ADD CONSTRAINT FK_6BAF78707D5987D8 FOREIGN KEY (default_cooking_unit) REFERENCES unit (slug) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE ingredient ADD CONSTRAINT FK_6BAF78709A295823 FOREIGN KEY (default_purchase_unit) REFERENCES unit (slug) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE meal ADD CONSTRAINT FK_9EF68E9C3DC1A459 FOREIGN KEY (shopping_list) REFERENCES shopping_list (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE meal ADD CONSTRAINT FK_9EF68E9CDA88B137 FOREIGN KEY (recipe) REFERENCES recipe (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE recipe ADD CONSTRAINT FK_DA88B137BDAFD8C8 FOREIGN KEY (author) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE recipe ADD CONSTRAINT FK_DA88B1378CDE5729 FOREIGN KEY (type) REFERENCES recipe_type (slug) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE recipe_row ADD CONSTRAINT FK_F56792976BAF7870 FOREIGN KEY (ingredient) REFERENCES ingredient (slug) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE recipe_row ADD CONSTRAINT FK_F5679297DCBB0C53 FOREIGN KEY (unit) REFERENCES unit (slug) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE recipe_row ADD CONSTRAINT FK_F5679297DA88B137 FOREIGN KEY (recipe) REFERENCES recipe (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE shopping_list ADD CONSTRAINT FK_3DC1A4597E3C61F9 FOREIGN KEY (owner_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE conversion DROP CONSTRAINT FK_BD912744E3026191');
        $this->addSql('ALTER TABLE conversion DROP CONSTRAINT FK_BD912744F2798017');
        $this->addSql('ALTER TABLE conversion DROP CONSTRAINT FK_BD9127446BAF7870');
        $this->addSql('ALTER TABLE ingredient DROP CONSTRAINT FK_6BAF7870CD1DE18A');
        $this->addSql('ALTER TABLE ingredient DROP CONSTRAINT FK_6BAF78707D5987D8');
        $this->addSql('ALTER TABLE ingredient DROP CONSTRAINT FK_6BAF78709A295823');
        $this->addSql('ALTER TABLE meal DROP CONSTRAINT FK_9EF68E9C3DC1A459');
        $this->addSql('ALTER TABLE meal DROP CONSTRAINT FK_9EF68E9CDA88B137');
        $this->addSql('ALTER TABLE recipe DROP CONSTRAINT FK_DA88B137BDAFD8C8');
        $this->addSql('ALTER TABLE recipe DROP CONSTRAINT FK_DA88B1378CDE5729');
        $this->addSql('ALTER TABLE recipe_row DROP CONSTRAINT FK_F56792976BAF7870');
        $this->addSql('ALTER TABLE recipe_row DROP CONSTRAINT FK_F5679297DCBB0C53');
        $this->addSql('ALTER TABLE recipe_row DROP CONSTRAINT FK_F5679297DA88B137');
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
