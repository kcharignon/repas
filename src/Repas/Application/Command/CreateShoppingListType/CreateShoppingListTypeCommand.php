<?php

namespace Repas\Repas\Application\Command\CreateShoppingListType;


readonly class CreateShoppingListTypeCommand
{
    /**
     * @param string $userId le propriétaire du type : l'utilisateur du jeton, jamais celui d'un corps de requête
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $userId,
    ) {
    }
}
