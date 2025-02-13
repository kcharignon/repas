<?php

namespace Repas\Repas\Application\CreateConversion;


readonly class CreateConversionCommand
{

    public function __construct(
        public string $startUnitSlug,
        public string $endUnitSlug,
        public float $coefficient,
        public ?string $ingredientSlug,
    ) {
    }
}
