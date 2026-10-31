<?php

namespace Repas\Repas\Infrastructure\Repository;

use Repas\Repas\Domain\Interface\ActiveShoppingListContents;
use Repas\Repas\Infrastructure\Reader\ActiveShoppingListReader;

/**
 * Le contenu de la liste active de l'utilisateur pour une écriture qui en tire un choix : `ActiveShoppingListReader`, le même code que les Loaders
 * de lecture.
 */
final readonly class ActiveShoppingListContentsPostgreSQLRepository implements ActiveShoppingListContents
{
    public function __construct(
        private ActiveShoppingListReader $reader,
    ) {
    }

    public function lines(string $userId): ?array
    {
        return $this->reader->lines($userId);
    }

    public function meals(string $userId): ?array
    {
        return $this->reader->meals($userId);
    }

    public function hasSentList(string $userId): bool
    {
        return $this->reader->hasSentList($userId);
    }
}
