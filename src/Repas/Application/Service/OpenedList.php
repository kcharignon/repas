<?php

namespace Repas\Repas\Application\Service;

use Repas\Repas\Domain\Model\ShoppingList;

/**
 * La liste en cours d'un type rendue par `ActiveListForType`, et si elle vient d'être ouverte à cette demande (ADR 0036 décision 7).
 */
final readonly class OpenedList
{
    public function __construct(
        public ShoppingList $shoppingList,
        public bool $opened,
    ) {
    }
}
