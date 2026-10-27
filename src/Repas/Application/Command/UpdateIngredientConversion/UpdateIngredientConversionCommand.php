<?php

namespace Repas\Repas\Application\Command\UpdateIngredientConversion;


readonly class UpdateIngredientConversionCommand
{
    /**
     * @param string $userId le propriétaire de l'ingrédient (jamais un passe-droit d'administrateur)
     */
    public function __construct(
        public string $conversionId,
        public string $ingredientSlug,
        public string $startUnitSlug,
        public string $endUnitSlug,
        public float $coefficient,
        public string $userId,
    ) {
    }
}
