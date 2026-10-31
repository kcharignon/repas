<?php

namespace Repas\Repas\Application\Command\CheckRowsAddedToDriveBasket;

/**
 * Ce que le drive a mis au panier (DRV-745) : les lignes de la liste ENVOYÉE de `$userId` dont l'ingrédient est dans `$ingredientSlugs`.
 * Les slugs arrivent déjà traduits par les alias de l'utilisateur, par l'appelant (le contexte `Drive`).
 */
readonly class CheckRowsAddedToDriveBasketCommand
{
    /**
     * @param list<string> $ingredientSlugs
     */
    public function __construct(
        public string $userId,
        public string $shoppingListId,
        public array $ingredientSlugs,
    ) {
    }
}
