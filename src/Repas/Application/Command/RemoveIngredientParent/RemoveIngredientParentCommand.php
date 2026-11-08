<?php

namespace Repas\Repas\Application\Command\RemoveIngredientParent;


readonly class RemoveIngredientParentCommand
{
    /**
     * @param string $ingredientSlug la partie qui redevient un ingrédient ordinaire
     * @param string $userId         son propriétaire (jamais un passe-droit d'administrateur)
     */
    public function __construct(
        public string $ingredientSlug,
        public string $userId,
    ) {
    }
}
