<?php

namespace Repas\Drive\Domain\Model;


/**
 * Ligne d'une liste de courses vue par le contexte `Drive` : l'ingrédient et l'unité d'achat, avec la dimension et le
 * facteur de base de l'unité (nuls pour une unité qui ne se convertit pas : cuillère, tranche…), et le rayon de Repas de
 * l'ingrédient (celui qui groupe la liste de courses ; DRV-642 en tire le rayon de l'enseigne où le chercher).
 */
final readonly class ShoppingListLineForDrive
{
    public function __construct(
        public string $ingredientSlug,
        public string $ingredientName,
        public float $quantity,
        public string $unitSlug,
        public string $unitName,
        /** Peut être vide (l'unité « unité » n'a pas de symbole) : le plan renvoie alors le nom. */
        public string $unitSymbol,
        /** Valeur de `Dimension`, null si l'unité n'en a pas. */
        public ?string $unitDimension,
        public ?float $unitBaseFactor,
        /** Slug du rayon de Repas de l'ingrédient ; null si l'on ne le connaît pas (jamais dans la base : la colonne est obligatoire). */
        public ?string $departmentSlug = null,
    ) {
    }
}
