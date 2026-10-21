<?php

namespace Repas\Repas\Application\Command\UpdateConversion;


readonly class UpdateConversionCommand
{
    /**
     * @param string $userId l'administrateur qui modifie la conversion : une conversion propre à un ingrédient n'est modifiable que sur le sien
     */
    public function __construct(
        public string  $id,
        public string  $startUnitSlug,
        public string  $endUnitSlug,
        public float   $coefficient,
        public ?string $ingredientSlug,
        public string  $userId,
    ) {
    }
}
