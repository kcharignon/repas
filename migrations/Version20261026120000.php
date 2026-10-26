<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mémoire de phrase des RECETTES de la commande vocale (ADR 0029 décision 13, VOX-305) : quand Alexa ne reconnaît pas une recette et que
 * l'utilisateur choisit l'une des propositions, la phrase (normalisée) est retenue pour lui, et la prochaine fois la même phrase donne tout de suite
 * cette recette. Jumelle de `spoken_phrase_choice` (les produits) mais SÉPARÉE : les deux recherches ne se mélangent jamais. Une table, aucune donnée :
 *
 * - `spoken_recipe_phrase_choice (user_id, phrase, recipe_id, times, updated_at)`, clé primaire sur `(user_id, phrase)` : une phrase désigne une
 *   seule recette par utilisateur (le dernier choix l'emporte, `times` compte les choix identiques de suite). `phrase` est la clé de
 *   `SpokenIngredientRanker::phraseKey`, bornée à 100 caractères.
 * - aucune clé étrangère (convention du dépôt, ADR 0013 et 0021) : la suppression du compte efface les lignes (`PurgeUserDataHandler`), la suppression
 *   d'une recette celles qui la désignent (`RemoveRecipeHandler`).
 * - un index sur `(user_id, recipe_id)` pour retrouver les phrases d'une recette.
 *
 * Additive : une nouvelle table que le code précédent ignore (ADR 0017 règle 6) ; le retour arrière du code ne défait pas le schéma. `down` supprime la
 * table, donc ce que les utilisateurs ont appris à Alexa : il ne se joue qu'à la main, sur décision humaine.
 */
final class Version20261026120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Table spoken_recipe_phrase_choice : la mémoire de phrase des recettes de la commande vocale (la recette que l\'utilisateur a choisie pour une phrase)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE spoken_recipe_phrase_choice (user_id VARCHAR(36) NOT NULL, phrase VARCHAR(100) NOT NULL, recipe_id VARCHAR(36) NOT NULL, times INT NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (user_id, phrase))');
        $this->addSql('CREATE INDEX idx_spoken_recipe_phrase_choice_recipe ON spoken_recipe_phrase_choice (user_id, recipe_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE spoken_recipe_phrase_choice');
    }
}
