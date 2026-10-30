<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Les recettes favorites (ADR 0034) : `recipe.favorite` dit « son auteur l'a mise en favoris » (une étoile sur sa fiche, un type « Favoris »
 * en tête de ses recettes). Une colonne, aucune donnée : `FALSE` partout, donc aucune recette n'est favorite au départ. L'auteur la
 * marque ensuite d'un geste (`PUT /api/v1/recipes/{id}/favorite`), jamais cette migration.
 *
 * Additive (ADR 0017 règle 6) : l'entité du code précédent ne mappe pas la colonne, ses `INSERT` la laissent à `FALSE` (la valeur par défaut
 * s'applique), ses `UPDATE` ne la touchent pas ; le retour arrière du code ne défait pas le schéma et ne perd rien (les favoris restent en
 * base, ignorés le temps du retour arrière). Rejouable sans erreur (`IF NOT EXISTS`).
 * `down` supprime la colonne, donc les favoris de chaque auteur. Il ne se joue qu'à la main, sur décision humaine.
 */
final class Version20261031100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Colonne recipe.favorite : la recette mise en favoris par son auteur (faux par défaut)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recipe ADD COLUMN IF NOT EXISTS favorite BOOLEAN DEFAULT FALSE NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recipe DROP COLUMN IF EXISTS favorite');
    }
}
