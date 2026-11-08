<?php

namespace Repas\Repas\Application\Command\SetIngredientParent;


readonly class SetIngredientParentCommand
{
    /**
     * @param string $ingredientSlug la partie : l'ingrédient qu'on découpe (« Jaune d'œuf »)
     * @param string $parentSlug     son parent (« Œuf ») : à l'utilisateur lui aussi
     * @param float  $ratio          combien d'unités par défaut de la partie font UNE unité de recette du parent
     * @param string $userId         le propriétaire des deux ingrédients (jamais un passe-droit d'administrateur)
     */
    public function __construct(
        public string $ingredientSlug,
        public string $parentSlug,
        public float $ratio,
        public string $userId,
    ) {
    }
}
