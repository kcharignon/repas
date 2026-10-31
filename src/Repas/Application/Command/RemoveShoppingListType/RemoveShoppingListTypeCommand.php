<?php

namespace Repas\Repas\Application\Command\RemoveShoppingListType;


readonly class RemoveShoppingListTypeCommand
{
    /**
     * @param string $userId le propriétaire du type : l'utilisateur du jeton (jamais un passe-droit d'administrateur)
     */
    public function __construct(
        public string $id,
        public string $userId,
    ) {
    }
}
