<?php

namespace Repas\Repas\Domain\Model;


/**
 * L'un des deux ingrédients d'une fusion, réduit à ce que les règles de la fusion regardent : son slug, les unités (d'achat, de recette, les
 * compatibles) et le parent dont il est une partie (ADR 0038). Une valeur de slugs (ADR 0013) : le cas d'usage la tire d'un `Ingredient`,
 * l'aperçu d'une ligne SQL, et `IngredientMergePlanner` rend le même verdict pour l'un comme pour l'autre.
 *
 * Combien de parties il a est un fait de la base (`IngredientMergeUsage`), pas de l'ingrédient chargé : un modèle ne connaît pas ses parties.
 */
final readonly class MergeParty
{
    /**
     * @param list<string> $compatibleUnitSlugs les unités compatibles de l'ingrédient (celles que l'éditeur de recette lui propose)
     * @param ?string $parentSlug le slug de l'ingrédient dont celui-ci est une partie, `null` pour un ingrédient ordinaire ou un parent
     * @param string $cookingUnitSlug son unité de recette par défaut : celle dont parle le ratio de ses parties s'il en a
     */
    public function __construct(
        public string $slug,
        public string $purchaseUnitSlug,
        public array $compatibleUnitSlugs = [],
        public ?string $parentSlug = null,
        public string $cookingUnitSlug = '',
    ) {
    }

    public static function of(Ingredient $ingredient): self
    {
        return new self(
            $ingredient->getSlug(),
            $ingredient->getDefaultPurchaseUnit()->getSlug(),
            array_values($ingredient->getCompatibleUnits()->map(static fn(Unit $unit): string => $unit->getSlug())->toArray()),
            $ingredient->getParentSlug(),
            $ingredient->getDefaultCookingUnit()->getSlug(),
        );
    }
}
