<?php

namespace Repas\Repas\Infrastructure\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mapping de `spoken_recipe_phrase_choice` (ADR 0029 décision 13, VOX-305) : « pour cet utilisateur, cette phrase dite à Alexa désigne cette
 * recette ». Jumelle de `SpokenPhraseChoice` (les produits), séparée pour que les deux recherches ne se mélangent jamais. Sert à créer le schéma et
 * à décrire les colonnes ; les lectures et les écritures passent par `SpokenRecipePhraseChoicePostgreSQLRepository` et
 * `SpokenRecipePhraseMemoryPostgreSQLLoader` (SQL direct), jamais par l'ORM : la ligne n'est jamais hydratée.
 *
 * `phrase` est la clé de `SpokenIngredientRanker::phraseKey` (jamais la phrase brute). Aucune clé étrangère (ADR 0013, 0021) : `userId` est effacé
 * avec le compte (`PurgeUserDataHandler`), `recipeId` avec la recette (`RemoveRecipeHandler`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'spoken_recipe_phrase_choice')]
#[ORM\Index(name: 'idx_spoken_recipe_phrase_choice_recipe', columns: ['user_id', 'recipe_id'])]
class SpokenRecipePhraseChoice
{
    #[ORM\Id]
    #[ORM\Column(name: 'user_id', type: Types::STRING, length: 36)]
    public string $userId;

    #[ORM\Id]
    #[ORM\Column(name: 'phrase', type: Types::STRING, length: 100)]
    public string $phrase;

    #[ORM\Column(name: 'recipe_id', type: Types::STRING, length: 36)]
    public string $recipeId;

    #[ORM\Column(name: 'times', type: Types::INTEGER)]
    public int $times;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $updatedAt;
}
