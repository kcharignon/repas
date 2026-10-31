<?php

namespace Repas\Repas\Infrastructure\Loader;

use Repas\Repas\Application\Loader\ActiveShoppingListLines;
use Repas\Repas\Infrastructure\Reader\ActiveShoppingListReader;

/**
 * Les lignes de la liste active de l'utilisateur en deux requêtes : `ActiveShoppingListReader`, le même code que la lecture d'une écriture en cours
 * (`ActiveShoppingListContentsPostgreSQLRepository`).
 */
final readonly class ActiveShoppingListLinesPostgreSQLLoader implements ActiveShoppingListLines
{
    public function __construct(
        private ActiveShoppingListReader $reader,
    ) {
    }

    public function forUser(string $userId): ?array
    {
        return $this->reader->lines($userId);
    }

    public function hasSentList(string $userId): bool
    {
        return $this->reader->hasSentList($userId);
    }
}
