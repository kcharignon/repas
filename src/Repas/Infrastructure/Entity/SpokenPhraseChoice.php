<?php

namespace Repas\Repas\Infrastructure\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mapping de `spoken_phrase_choice` (ADR 0029 décision 11, VOX-303) : « pour cet utilisateur, cette phrase dite à Alexa désigne cet
 * ingrédient ». Sert à créer le schéma et à décrire les colonnes ; les lectures et les écritures passent par
 * `SpokenPhraseChoicePostgreSQLRepository` et `SpokenPhraseMemoryPostgreSQLLoader` (SQL direct), jamais par l'ORM : la ligne n'est jamais
 * hydratée.
 *
 * `phrase` est la clé de `SpokenIngredientRanker::phraseKey` (jamais la phrase brute). Aucune clé étrangère (ADR 0013, 0021) : `userId` est
 * effacé avec le compte (`PurgeUserDataHandler`), `ingredientSlug` avec l'ingrédient (`RemoveIngredientHandler`) ou repointé par la fusion
 * (`MergeIngredientsHandler`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'spoken_phrase_choice')]
#[ORM\Index(name: 'idx_spoken_phrase_choice_ingredient', columns: ['user_id', 'ingredient_slug'])]
class SpokenPhraseChoice
{
    #[ORM\Id]
    #[ORM\Column(name: 'user_id', type: Types::STRING, length: 36)]
    public string $userId;

    #[ORM\Id]
    #[ORM\Column(name: 'phrase', type: Types::STRING, length: 100)]
    public string $phrase;

    #[ORM\Column(name: 'ingredient_slug', type: Types::STRING, length: 255)]
    public string $ingredientSlug;

    #[ORM\Column(name: 'times', type: Types::INTEGER)]
    public int $times;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $updatedAt;
}
