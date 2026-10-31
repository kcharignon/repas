<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Les types de liste de courses (ADR 0036, LST-002), le schéma COMPLET du chantier en une seule migration :
 *
 *  - `shopping_list_type` : un type que l'utilisateur crée (« Boucherie », « Marché »), propre à son compte. `slugged_name` est le nom
 *    normalisé, unique par compte (règle de l'application, aucune contrainte en base).
 *  - `department_list_type` : le rayon d'un utilisateur rattaché à un type. La clé est (utilisateur, rayon) : un rayon est dans un seul type
 *    à la fois, rattacher un rayon déjà pris le déplace.
 *  - `shopping_list.list_type_id` : le type de la liste (vide = la liste principale, celle d'aujourd'hui).
 *  - `ingredient.list_destination` : le choix fait sur le produit (vide = « comme son rayon », `main` = la liste principale, sinon un type).
 *  - `meal.planned_group_id` : l'identifiant de la planification qui a créé le repas (les repas d'une même planification éclatée le partagent).
 *
 * Aucune donnée n'est écrite : aucun type n'existe, toute colonne est vide, donc rien ne change pour personne. LST-002 ne lit encore que les
 * deux tables ; les trois colonnes sont lues par LST-003 à 005.
 *
 * Additive (ADR 0017 règle 6) : les entités du code précédent ne mappent aucune de ces colonnes, leurs `INSERT` les laissent à `NULL`, leurs
 * `UPDATE` ne les touchent pas, et les deux tables sont ignorées : un retour arrière du code ne voit que la liste principale. Le retour
 * arrière ne défait pas le schéma. Aucune clé étrangère (ADR 0013, 0021). Rejouable sans erreur (`IF NOT EXISTS`).
 * `down` supprime les tables et les colonnes, donc les types, leurs rayons, les choix de produits et les liens entre repas : il ne se joue
 * qu'à la main, sur décision humaine.
 */
final class Version20261031120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Types de liste de courses : tables shopping_list_type et department_list_type, colonnes shopping_list.list_type_id, ingredient.list_destination et meal.planned_group_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS shopping_list_type (id VARCHAR(36) NOT NULL, owner_id VARCHAR(36) NOT NULL, name VARCHAR(255) NOT NULL, slugged_name VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_shopping_list_type_owner ON shopping_list_type (owner_id, created_at)');
        $this->addSql('CREATE TABLE IF NOT EXISTS department_list_type (owner_id VARCHAR(36) NOT NULL, department_slug VARCHAR(255) NOT NULL, list_type_id VARCHAR(36) NOT NULL, PRIMARY KEY (owner_id, department_slug))');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_department_list_type_type ON department_list_type (list_type_id)');
        $this->addSql('ALTER TABLE shopping_list ADD COLUMN IF NOT EXISTS list_type_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE ingredient ADD COLUMN IF NOT EXISTS list_destination VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE meal ADD COLUMN IF NOT EXISTS planned_group_id VARCHAR(36) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE meal DROP COLUMN IF EXISTS planned_group_id');
        $this->addSql('ALTER TABLE ingredient DROP COLUMN IF EXISTS list_destination');
        $this->addSql('ALTER TABLE shopping_list DROP COLUMN IF EXISTS list_type_id');
        $this->addSql('DROP TABLE IF EXISTS department_list_type');
        $this->addSql('DROP TABLE IF EXISTS shopping_list_type');
    }
}
