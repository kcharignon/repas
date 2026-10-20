<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le marqueur « ingrédient de base » (amendement du 2026-10-20 de l'ADR 0028, ING-011) : `ingredient.is_base` dit « cet ingrédient part aux
 * nouveaux comptes ». Il n'a de sens que chez le propriétaire du catalogue ; l'amorçage des comptes (ING-008) le lit, rien d'autre.
 * Une colonne, aucune donnée : `FALSE` partout jusqu'à la passation des anciens communs (ING-009).
 *
 * Additive : l'entité du code précédent ne mappe pas la colonne, ses `UPDATE` ne la touchent pas et la valeur par défaut s'applique à ses
 * `INSERT` (ADR 0017 règle 6) ; le retour arrière du code ne défait pas le schéma.
 * `down` supprime la colonne, donc le classement « de base » posé jusque-là : il ne se joue qu'à la main, sur décision humaine (le
 * classement se repose depuis `docs/ingredients/base-ingredients.tsv` par la passation, mais pas ce que le propriétaire aurait marqué ensuite).
 */
final class Version20261021160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Colonne ingredient.is_base : le marqueur « ingrédient de base » (faux par défaut)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ingredient ADD is_base BOOLEAN DEFAULT FALSE NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ingredient DROP is_base');
    }
}
