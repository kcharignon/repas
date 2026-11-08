<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Les recettes utilisées dans d'autres recettes (ADR 0040, COMP-001) : une recette (« Tarte au citron ») en utilise d'autres du même compte
 * (« Pâte sablée », « 2 fois »), par des COMPOSANTS.
 *
 *  - la table `recipe_component (id, recipe, component, multiplier, position)` : `recipe` est la recette composée, `component` la recette
 *    utilisée, `multiplier` combien de fois cette recette telle qu'elle est écrite, `position` son rang ; l'index unique
 *    `uniq_recipe_component (recipe, component)` interdit d'utiliser deux fois la même recette et sert aussi à lire les composants d'une recette ;
 *    l'index `idx_recipe_component_component (component)` répond à « cette recette sert-elle quelque part ? » (suppression refusée, secrète refusée) ;
 *  - `recipe.component_count` : le nombre de composants de la recette, lu avec la recette elle-même (la même ligne) : on sait qu'une recette n'en
 *    a pas sans une requête de plus, et les arêtes ne sont lues que si le compte est supérieur à zéro.
 *
 * Aucune donnée n'est écrite : la table est vide et la colonne vaut `0` partout, donc rien ne change pour personne tant qu'aucune recette ne
 * reçoit de composant (COMP-002, qui ne part jamais en production avant l'aplatissement de la planification, COMP-004 : ADR 0040 décision 32).
 *
 * Additive (ADR 0017 règle 6) : le code précédent ignore la table et la colonne (ses `INSERT` laissent `component_count` à `0`, ses `UPDATE` n'y
 * touchent pas), le retour arrière du code ne défait pas le schéma. Mais une fois des composants créés, l'ancien code planifierait une recette
 * composée SANS les ingrédients de ses recettes utilisées, supprimerait une recette qui sert et oublierait les arêtes d'un compte supprimé : on ne
 * revient pas à une version antérieure du back sans prévenir (`docs/production.md`, ADR 0040 décision 43). Aucune clé étrangère (ADR 0013, 0021) :
 * les lectures tolèrent une arête orpheline. Rejouable sans erreur (`IF NOT EXISTS`).
 * `down` supprime les index, la table puis la colonne, donc tous les composants : il ne se joue qu'à la main, sur décision humaine.
 */
final class Version20261108180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recettes utilisées dans d\'autres recettes : la table recipe_component et recipe.component_count';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS recipe_component (id VARCHAR(36) NOT NULL, recipe VARCHAR(36) NOT NULL, component VARCHAR(36) NOT NULL, multiplier DOUBLE PRECISION NOT NULL, position INT DEFAULT 0 NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_recipe_component ON recipe_component (recipe, component)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_recipe_component_component ON recipe_component (component)');
        $this->addSql('ALTER TABLE recipe ADD COLUMN IF NOT EXISTS component_count INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_recipe_component_component');
        $this->addSql('DROP INDEX IF EXISTS uniq_recipe_component');
        $this->addSql('DROP TABLE IF EXISTS recipe_component');
        $this->addSql('ALTER TABLE recipe DROP COLUMN IF EXISTS component_count');
    }
}
