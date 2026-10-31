<?php

namespace Repas\Repas\Application\Command\UpdateShoppingListType;


readonly class UpdateShoppingListTypeCommand
{
    /**
     * @param list<string> $departmentSlugs le jeu COMPLET des rayons du type (ceux qui n'y sont plus sont détachés)
     * @param string $userId le propriétaire du type : l'utilisateur du jeton (jamais un passe-droit d'administrateur)
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $departmentSlugs,
        public string $userId,
    ) {
    }
}
