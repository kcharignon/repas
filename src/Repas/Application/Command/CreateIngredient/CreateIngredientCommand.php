<?php

namespace Repas\Repas\Application\Command\CreateIngredient;


readonly class CreateIngredientCommand
{
    public function __construct(
        public string  $name,
        public string  $image,
        public string  $departmentSlug,
        public string  $defaultCookingUnitSlug,
        public string  $defaultPurchaseUnitSlug,
        public string $userId,
        public bool    $isAdmin,
        public ?float  $coefficient,
    ) {
    }
}
