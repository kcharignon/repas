<?php

namespace Repas\Repas\Infrastructure\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Interface\SpokenRecipePhraseChoiceRepository;
use Repas\Repas\Infrastructure\Entity\SpokenRecipePhraseChoice as SpokenRecipePhraseChoiceEntity;
use Repas\Shared\Infrastructure\Repository\PostgreSQLRepository;

/**
 * Écritures en SQL direct (ADR 0029) : une requête par appel, la ligne n'est jamais hydratée par l'ORM. La clé primaire `(user_id, phrase)` arbitre
 * deux choix simultanés de la même phrase.
 */
readonly class SpokenRecipePhraseChoicePostgreSQLRepository extends PostgreSQLRepository implements SpokenRecipePhraseChoiceRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
    ) {
        parent::__construct($managerRegistry, SpokenRecipePhraseChoiceEntity::class);
    }

    public function remember(string $userId, string $phraseKey, string $recipeId, DateTimeImmutable $now): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO spoken_recipe_phrase_choice (user_id, phrase, recipe_id, times, updated_at) VALUES (?, ?, ?, 1, ?) '
            . 'ON CONFLICT (user_id, phrase) DO UPDATE SET '
            . 'times = CASE WHEN spoken_recipe_phrase_choice.recipe_id = EXCLUDED.recipe_id THEN spoken_recipe_phrase_choice.times + 1 ELSE 1 END, '
            . 'recipe_id = EXCLUDED.recipe_id, updated_at = EXCLUDED.updated_at',
            [$userId, $phraseKey, $recipeId, $now],
            [3 => Types::DATETIME_IMMUTABLE],
        );
    }

    public function deleteByUser(string $userId): void
    {
        $this->connection()->executeStatement('DELETE FROM spoken_recipe_phrase_choice WHERE user_id = ?', [$userId]);
    }

    public function deleteByRecipeId(string $userId, string $recipeId): void
    {
        $this->connection()->executeStatement('DELETE FROM spoken_recipe_phrase_choice WHERE user_id = ? AND recipe_id = ?', [$userId, $recipeId]);
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
