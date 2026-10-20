<?php

namespace Repas\Repas\Application\Command\CreateIngredient;


readonly class CreateIngredientCommand
{
    /**
     * @param bool $isBase le marqueur « de base » demandé (ADR 0028, amendement) ; `false` est la valeur de toute création, et seul le
     *                     propriétaire du catalogue peut demander `true`
     */
    public function __construct(
        public string  $name,
        public string  $image,
        public string  $departmentSlug,
        public string  $defaultCookingUnitSlug,
        public string  $defaultPurchaseUnitSlug,
        public string $userId,
        public ?float  $coefficient,
        public bool    $isBase = false,
    ) {
    }
}
