<?php

namespace Repas\Repas\Infrastructure\Repository;

use Repas\Repas\Domain\Interface\IngredientPhraseCandidates;
use Repas\Repas\Infrastructure\Reader\SpokenIngredientPhraseReader;

/**
 * Les ingrédients de l'utilisateur et son habitude de chacun, pour une écriture qui en tire un choix : `SpokenIngredientPhraseReader`, le même
 * code que le Loader de lecture (`IngredientVisibility::visibleBy`, trois requêtes quel que soit le nombre d'ingrédients).
 */
final readonly class IngredientPhraseCandidatesPostgreSQLRepository implements IngredientPhraseCandidates
{
    public function __construct(
        private SpokenIngredientPhraseReader $reader,
    ) {
    }

    public function forUser(string $userId): array
    {
        return $this->reader->candidates($userId);
    }
}
