<?php

namespace Repas\Repas\Application\OpenHostService;

/**
 * Ligne d'une liste de courses publiée par `Repas` : l'ingrédient et l'unité d'achat, avec la dimension et le facteur de base de l'unité
 * (nuls pour une unité qui ne se convertit pas : cuillère, tranche…), et le rayon de l'ingrédient (celui qui groupe la liste de courses).
 */
final readonly class ShoppingListContentLineDTO
{
    public function __construct(
        public string $ingredientSlug,
        public string $ingredientName,
        public float $quantity,
        public string $unitSlug,
        public string $unitName,
        /** Peut être vide (l'unité « unité » n'a pas de symbole). */
        public string $unitSymbol,
        /** Valeur de `Shared\Domain\Model\Dimension`, null si l'unité n'en a pas. */
        public ?string $unitDimension,
        public ?float $unitBaseFactor,
        /** Slug du rayon de l'ingrédient ; null si on ne le connaît pas (jamais dans la base : la colonne est obligatoire). */
        public ?string $departmentSlug = null,
    ) {
    }
}
