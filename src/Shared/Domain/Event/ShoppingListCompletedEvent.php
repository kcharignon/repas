<?php

namespace Repas\Shared\Domain\Event;


use DateTimeImmutable;

/**
 * Une liste de courses vient d'être terminée : le contexte `User` en tient la statistique du propriétaire. L'événement
 * porte ce qu'il faut (identifiants, date, effectifs), le contexte `User` n'a ni à lire la liste ni à importer son modèle.
 */
readonly class ShoppingListCompletedEvent
{

    public function __construct(
        public string $ownerId,
        public string $shoppingListId,
        public DateTimeImmutable $createdAt,
        public int $mealCount,
        public int $ingredientCount,
        public int $rowCount,
    ) {
    }
}
