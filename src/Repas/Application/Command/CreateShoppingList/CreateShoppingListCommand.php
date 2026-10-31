<?php

namespace Repas\Repas\Application\Command\CreateShoppingList;


class CreateShoppingListCommand
{

    /**
     * @param ?string $listTypeId le type de la liste (ADR 0036) ; `null` : la liste principale, comme avant les types
     */
    public function __construct(
        public string $shoppingListId,
        public string $ownerId,
        public ?string $listTypeId = null,
    ) {
    }
}
