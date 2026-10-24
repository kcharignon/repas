<?php

namespace Repas\Repas\Application\Command\MergeIngredients;


readonly class MergeIngredientsCommand
{
    /**
     * @param string $userId l'utilisateur du jeton : les deux ingrédients doivent être à lui, sans exception pour un administrateur
     * @param string $absorbedSlug l'ingrédient qui disparaît (`B`)
     * @param string $keptSlug l'ingrédient qui reste et reçoit tout (`A`)
     */
    public function __construct(
        public string $userId,
        public string $absorbedSlug,
        public string $keptSlug,
    ) {
    }
}
