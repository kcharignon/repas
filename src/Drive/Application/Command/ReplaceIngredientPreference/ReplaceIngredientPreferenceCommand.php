<?php

namespace Repas\Drive\Application\Command\ReplaceIngredientPreference;


use Repas\Drive\Domain\Model\Retailer;

readonly class ReplaceIngredientPreferenceCommand
{
    /**
     * @param list<string> $acceptedProductIds identifiants Repas des produits acceptés, le préféré d'abord
     * @param list<string> $rejectedProductIds identifiants Repas des produits refusés
     */
    public function __construct(
        public string $userId,
        public Retailer $retailer,
        public string $storeId,
        public string $ingredientSlug,
        public bool $haveAtHome,
        public array $acceptedProductIds,
        public array $rejectedProductIds,
    ) {
    }
}
