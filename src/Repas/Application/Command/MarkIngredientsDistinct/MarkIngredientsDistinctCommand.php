<?php

namespace Repas\Repas\Application\Command\MarkIngredientsDistinct;


readonly class MarkIngredientsDistinctCommand
{
    /**
     * @param string $userId l'utilisateur du jeton : les deux ingrédients doivent être à lui, sans exception pour un administrateur
     * @param string $slug l'un des deux ingrédients
     * @param string $otherSlug l'autre (l'ordre est sans importance)
     */
    public function __construct(
        public string $userId,
        public string $slug,
        public string $otherSlug,
    ) {
    }
}
