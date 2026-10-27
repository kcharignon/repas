<?php

namespace Repas\Repas\Application\Command\CreateIngredientConversion;


readonly class CreateIngredientConversionCommand
{
    /**
     * @param string $userId le propriétaire de l'ingrédient (jamais un passe-droit d'administrateur)
     */
    public function __construct(
        public string $id,
        public string $ingredientSlug,
        public string $startUnitSlug,
        public string $endUnitSlug,
        public float $coefficient,
        public string $userId,
    ) {
    }
}
