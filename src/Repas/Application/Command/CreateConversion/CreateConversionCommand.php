<?php

namespace Repas\Repas\Application\Command\CreateConversion;


readonly class CreateConversionCommand
{

    /**
     * @param string $userId l'administrateur qui crée la conversion : une conversion propre à un ingrédient n'est permise que sur le sien
     */
    public function __construct(
        public string $id,
        public string $startUnitSlug,
        public string $endUnitSlug,
        public float $coefficient,
        public ?string $ingredientSlug,
        public string $userId,
    ) {
    }
}
