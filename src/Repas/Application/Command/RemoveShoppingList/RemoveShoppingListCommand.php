<?php

namespace Repas\Repas\Application\Command\RemoveShoppingList;

use Repas\Repas\Domain\Model\ShoppingListArchiveCause;

readonly class RemoveShoppingListCommand
{
    /**
     * @param ShoppingListArchiveCause $cause pourquoi la liste part (ADR 0041) : la personne l'a supprimée, ou c'est le ménage d'une liste terminée
     */
    public function __construct(
        public string $shoppingListId,
        public ShoppingListArchiveCause $cause = ShoppingListArchiveCause::DELETED_BY_USER,
    ) {
    }
}
