<?php

namespace Repas\Repas\Application\Command\DeleteIngredientConversion;


readonly class DeleteIngredientConversionCommand
{
    /**
     * @param string $userId le propriétaire de l'ingrédient (jamais un passe-droit d'administrateur)
     */
    public function __construct(
        public string $conversionId,
        public string $ingredientSlug,
        public string $userId,
    ) {
    }
}
