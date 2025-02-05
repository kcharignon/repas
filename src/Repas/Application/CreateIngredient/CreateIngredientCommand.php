<?php

namespace Repas\Repas\Application\CreateIngredient;


readonly class CreateIngredientCommand
{
    public function __construct(
        public string  $name,
        public string  $image,
        public string  $departmentSlug,
        public string  $defaultCookingUnitSlug,
        public string  $defaultPurchaseUnitSlug,
        public ?string $ownerId,
    ) {
    }
}
