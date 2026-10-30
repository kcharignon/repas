<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * L'icône d'un rayon (amendement du 2026-10-30 de l'ADR 0028, ING-016 B) : `department.icon` est le nom d'une icône du front (`snowflake`
 * pour Surgelés, `can` pour Conserves, d'autres plus tard), réglé par l'administrateur sur l'écran des rayons. Elle sert à distinguer à
 * l'écran deux ingrédients de même nom dans deux rayons. Les valeurs permises sont celles de `DepartmentIcon` (le domaine) ; la colonne n'est
 * qu'un texte court, `NULL` quand le rayon n'a pas d'icône. Une colonne, aucune donnée : `NULL` partout, la valeur se règle ensuite par
 * l'écran d'administration (jamais par cette migration).
 *
 * Additive (ADR 0017 règle 6) : l'entité du code précédent ne mappe pas la colonne, ses `INSERT` la laissent à `NULL`, ses `UPDATE` ne la
 * touchent pas ; le retour arrière du code ne défait pas le schéma. Rejouable sans erreur (`IF NOT EXISTS`).
 * `down` supprime la colonne, donc les icônes réglées jusque-là : elles se règlent à nouveau d'un geste, rien de précieux ne se perd.
 */
final class Version20261030190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Colonne department.icon : l'icône du rayon (facultative, NULL par défaut)";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE department ADD COLUMN IF NOT EXISTS icon VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE department DROP COLUMN IF EXISTS icon');
    }
}
