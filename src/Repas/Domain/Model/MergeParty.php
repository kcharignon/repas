<?php

namespace Repas\Repas\Domain\Model;


/**
 * L'un des deux ingrédients d'une fusion, réduit à ce que les règles de la fusion regardent : son slug, s'il est commun, l'unité
 * d'achat. Une valeur de slugs et de booléens (ADR 0013) : le cas d'usage la tire d'un `Ingredient`, l'aperçu d'une ligne SQL, et
 * `IngredientMergePlanner` rend le même verdict pour l'un comme pour l'autre.
 */
final readonly class MergeParty
{
    public function __construct(
        public string $slug,
        public bool $isCommon,
        public string $purchaseUnitSlug,
    ) {
    }

    public static function of(Ingredient $ingredient): self
    {
        return new self($ingredient->getSlug(), $ingredient->isCommon(), $ingredient->getDefaultPurchaseUnit()->getSlug());
    }
}
