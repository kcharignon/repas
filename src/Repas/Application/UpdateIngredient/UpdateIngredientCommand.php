<?php

namespace Repas\Repas\Application\UpdateIngredient;


readonly class UpdateIngredientCommand
{
    public function __construct(
        public string  $slug,
        public string  $name,
        public string  $image,
        public string  $departmentSlug,
        public string  $defaultCookingUnitSlug,
        public string  $defaultPurchaseUnitSlug,
        public ?float  $coefficient,
    ) {
    }

}
