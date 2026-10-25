<?php

namespace Repas\Repas\Infrastructure\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Interface\SpokenPhraseChoiceRepository;
use Repas\Repas\Infrastructure\Entity\SpokenPhraseChoice as SpokenPhraseChoiceEntity;
use Repas\Shared\Infrastructure\Repository\PostgreSQLRepository;

/**
 * Écritures en SQL direct (ADR 0029) : une requête par appel, la ligne n'est jamais hydratée par l'ORM. La clé primaire `(user_id, phrase)`
 * arbitre deux choix simultanés de la même phrase.
 */
readonly class SpokenPhraseChoicePostgreSQLRepository extends PostgreSQLRepository implements SpokenPhraseChoiceRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
    ) {
        parent::__construct($managerRegistry, SpokenPhraseChoiceEntity::class);
    }

    public function remember(string $userId, string $phraseKey, string $ingredientSlug, DateTimeImmutable $now): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO spoken_phrase_choice (user_id, phrase, ingredient_slug, times, updated_at) VALUES (?, ?, ?, 1, ?) '
            . 'ON CONFLICT (user_id, phrase) DO UPDATE SET '
            . 'times = CASE WHEN spoken_phrase_choice.ingredient_slug = EXCLUDED.ingredient_slug THEN spoken_phrase_choice.times + 1 ELSE 1 END, '
            . 'ingredient_slug = EXCLUDED.ingredient_slug, updated_at = EXCLUDED.updated_at',
            [$userId, $phraseKey, $ingredientSlug, $now],
            [3 => Types::DATETIME_IMMUTABLE],
        );
    }

    public function deleteByUser(string $userId): void
    {
        $this->connection()->executeStatement('DELETE FROM spoken_phrase_choice WHERE user_id = ?', [$userId]);
    }

    public function deleteByIngredientSlug(string $userId, string $ingredientSlug): void
    {
        $this->connection()->executeStatement('DELETE FROM spoken_phrase_choice WHERE user_id = ? AND ingredient_slug = ?', [$userId, $ingredientSlug]);
    }

    public function repoint(string $userId, string $fromSlug, string $toSlug): void
    {
        $this->connection()->executeStatement(
            'UPDATE spoken_phrase_choice SET ingredient_slug = ? WHERE user_id = ? AND ingredient_slug = ?',
            [$toSlug, $userId, $fromSlug],
        );
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
