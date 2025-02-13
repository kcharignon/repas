<?php

namespace Repas\Repas\Application\UpdateConversion;


readonly class UpdateConversionCommand
{
    public function __construct(
        public string  $id,
        public string  $startUnitSlug,
        public string  $endUnitSlug,
        public float   $coefficient,
        public ?string $ingredientSlug,
    ) {
    }
}
