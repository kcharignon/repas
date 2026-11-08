<?php

namespace Repas\Repas\Domain\Service;


use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Model\Ingredient;

/**
 * Ce que vaut une quantité d'une PARTIE d'ingrédient en unités d'achat de son parent (ADR 0038 décisions 3 et 4) : 2 jaunes d'œuf valent 2 œufs
 * (ratio 1), 3 demi-gousses de vanille valent 1,5 gousse (ratio 2).
 *
 * Le ratio dit combien d'unités par défaut de la partie font UNE unité de recette du parent ; la quantité de la partie est donc d'abord ramenée en
 * unités de recette du parent (`quantité / ratio`), puis convertie dans son unité d'achat par le chemin ordinaire du parent (`ConversionService`,
 * l'unité de recette d'un ingrédient se convertit toujours en son unité d'achat, ADR 0028). Aucun arrondi : 3 jaunes à raison de 2 par unité font 1,5.
 *
 * La quantité de la partie est déjà dans son unité d'achat (celle des lignes d'une liste), comme le ratio le suppose : `convertRecipeRowToPurchaseUnit`
 * l'y a amenée avant. Service de domaine sans état : le seul savoir est le graphe de conversions du parent.
 */
readonly class PartEquivalence
{
    public function __construct(
        private ConversionService $conversionService,
    ) {
    }

    /**
     * @param float $quantityInPartPurchaseUnit une quantité de la partie, dans son unité d'achat
     *
     * @return float la même quantité vue du parent, dans l'unité d'achat du parent
     *
     * @throws IngredientException le ratio de la partie n'a pas de sens (jamais pour une partie enregistrée par le cas d'usage), ou le parent ne
     *                             convertit pas son unité de recette en son unité d'achat
     */
    public function of(Ingredient $part, Ingredient $parent, float $quantityInPartPurchaseUnit): float
    {
        $ratio = $part->getParentRatio();
        if ($ratio === null || !is_finite($ratio) || $ratio <= 0) {
            throw IngredientException::partRatioInvalid();
        }

        return $this->conversionService->convertTo(
            $parent,
            $quantityInPartPurchaseUnit / $ratio,
            $parent->getDefaultCookingUnit(),
            $parent->getDefaultPurchaseUnit(),
        );
    }
}
