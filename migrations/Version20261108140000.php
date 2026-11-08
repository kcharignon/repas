<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Les sous-ingrédients (ADR 0038, SUB-001) : une PARTIE (« Jaune d'œuf ») est un ingrédient du compte qui fait partie d'un autre, son parent
 * (« Œuf »).
 *
 *  - `ingredient.parent_slug` : le slug du parent de la partie, `NULL` pour tout ingrédient ordinaire (et pour tout parent) ;
 *  - `ingredient.parent_ratio` : combien d'unités par défaut de la partie font UNE unité de recette du parent (1 œuf = 1 jaune : `1`) ;
 *    posé avec `parent_slug` ou pas du tout (règle du domaine, aucune contrainte en base) ;
 *  - l'index partiel `(creator, parent_slug)` : retrouver les parties d'un parent (le plafond de 10, la fiche, la fusion) sans lire tout le
 *    catalogue, et rien pour les ingrédients ordinaires.
 *
 * Aucune donnée n'est écrite : la colonne est vide partout, donc rien ne change pour personne ; les liens se créent par l'écran (le
 * propriétaire du catalogue) ou par les copies (SUB-002), jamais par cette migration (ADR 0038 décision 32).
 *
 * Additive (ADR 0017 règle 6) : l'entité du code précédent ne mappe aucune de ces colonnes, ses `INSERT` les laissent à `NULL`, ses `UPDATE`
 * n'y touchent pas ; le retour arrière du code ne défait pas le schéma. Mais l'ancien code ne connaît pas les parties : il traiterait
 * « Jaune d'œuf » comme un produit ordinaire (une ligne à part dans les listes) : on ne revient pas au code précédent sans prévenir une fois
 * des parties créées (`docs/production.md`). Aucune clé étrangère (ADR 0013, 0021). Rejouable sans erreur (`IF NOT EXISTS`).
 * `down` supprime l'index et les deux colonnes, donc tous les liens de découpage : ils se refont d'un geste par l'écran, rien d'autre n'est
 * perdu ; il ne se joue qu'à la main, sur décision humaine.
 */
final class Version20261108140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sous-ingrédients : ingredient.parent_slug, ingredient.parent_ratio et l\'index partiel (creator, parent_slug)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ingredient ADD COLUMN IF NOT EXISTS parent_slug VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE ingredient ADD COLUMN IF NOT EXISTS parent_ratio DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_ingredient_parent ON ingredient (creator, parent_slug) WHERE (parent_slug IS NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_ingredient_parent');
        $this->addSql('ALTER TABLE ingredient DROP COLUMN IF EXISTS parent_ratio');
        $this->addSql('ALTER TABLE ingredient DROP COLUMN IF EXISTS parent_slug');
    }
}
