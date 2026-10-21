<?php

namespace Repas\Repas\Domain\Model;


/**
 * Une conversion PROPRE d'un ingrédient (`conversion.ingredient` = lui), réduite à ce que la fusion regarde : son identifiant (la source de
 * la copie), les deux unités et le coefficient (`1 unité de départ = coefficient unités d'arrivée`). Une valeur de slugs et de nombres
 * (ADR 0013) : le cas d'usage la tire de la base par `usageOf()`, l'aperçu aussi, et `IngredientMergePlanner` décide laquelle l'ingrédient
 * gardé récupère (`docs/ingredients/fusion.md` § 3 étape 1).
 */
final readonly class MergeConversion
{
    public function __construct(
        public string $id,
        public string $startUnitSlug,
        public string $endUnitSlug,
        public float $coefficient,
    ) {
    }
}
