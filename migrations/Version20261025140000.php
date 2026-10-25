<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mémoire de phrase de la commande vocale (ADR 0029 décision 11, VOX-303) : quand Alexa ne reconnaît pas un produit et que l'utilisateur
 * choisit l'une des propositions, la phrase (normalisée) est retenue pour lui, et la prochaine fois la même phrase donne tout de suite cet
 * ingrédient. Une table, aucune donnée :
 *
 * - `spoken_phrase_choice (user_id, phrase, ingredient_slug, times, updated_at)`, clé primaire sur `(user_id, phrase)` : une phrase
 *   désigne un seul ingrédient par utilisateur (le dernier choix l'emporte, `times` compte les choix identiques de suite). `phrase` est la
 *   clé de `SpokenIngredientRanker::phraseKey` (mots normalisés joints par « - »), bornée à 100 caractères.
 * - aucune clé étrangère (convention du dépôt, ADR 0013 et 0021) : la suppression du compte efface les lignes (`PurgeUserDataHandler`), la
 *   suppression d'un ingrédient celles qui le désignent (`RemoveIngredientHandler`), la fusion les repointe (`MergeIngredientsHandler`).
 * - un index sur `(user_id, ingredient_slug)` pour retrouver les phrases d'un ingrédient (suppression, fusion).
 *
 * Additive : une nouvelle table que le code précédent ignore (ADR 0017 règle 6) ; le retour arrière du code ne défait pas le schéma.
 * `down` supprime la table, donc ce que les utilisateurs ont appris à Alexa : il ne se joue qu'à la main, sur décision humaine.
 */
final class Version20261025140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Table spoken_phrase_choice : la mémoire de phrase de la commande vocale (le produit que l\'utilisateur a choisi pour une phrase)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE spoken_phrase_choice (user_id VARCHAR(36) NOT NULL, phrase VARCHAR(100) NOT NULL, ingredient_slug VARCHAR(255) NOT NULL, times INT NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (user_id, phrase))');
        $this->addSql('CREATE INDEX idx_spoken_phrase_choice_ingredient ON spoken_phrase_choice (user_id, ingredient_slug)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE spoken_phrase_choice');
    }
}
