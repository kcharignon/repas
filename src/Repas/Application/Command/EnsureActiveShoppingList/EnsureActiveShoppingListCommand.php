<?php

namespace Repas\Repas\Application\Command\EnsureActiveShoppingList;


readonly class EnsureActiveShoppingListCommand
{
    /**
     * @param string $shoppingListId identifiant de la liste créée si l'utilisateur n'en a pas d'active
     */
    public function __construct(
        public string $shoppingListId,
        public string $ownerId,
    ) {
    }
}
