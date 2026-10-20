<?php

namespace Repas\Repas\Application\Query\ListDuplicateIngredients;

use OpenApi\Attributes as OA;

/**
 * Une paire d'ingrédients de l'utilisateur, du même rayon, que l'application pense être le même : la carte de l'écran « Doublons
 * possibles » (`docs/ingredients/fusion.md` § 9.1). `first` est celui dont le slug précède l'autre : l'ordre est stable d'un appel à
 * l'autre, il ne dit rien de celui à garder (`suggestedKeep`).
 */
final readonly class DuplicateIngredientDTO
{
    public function __construct(
        public DuplicateIngredientSideDTO $first,
        public DuplicateIngredientSideDTO $second,
        #[OA\Property(description: 'Ressemblance des noms normalisés, de 0,90 à 1 (Jaro-Winkler, 1 pour deux noms identiques une fois normalisés)', example: 1.0)]
        public float $score,
        #[OA\Property(
            description: '`EXACT` : le même nom une fois normalisé (casse, accents, pluriel, mots vides) ; `TYPO` : à une faute de frappe près (0,97 au moins) ; `SUGGESTION` : assez proche pour demander (de 0,90 à 0,97). Tolérer une valeur inconnue',
            enum: ['EXACT', 'TYPO', 'SUGGESTION'],
            example: 'EXACT',
        )]
        public string $level,
        #[OA\Property(description: 'Slug de celui des deux à garder par défaut : le plus utilisé en recettes, puis en listes, puis le nom le plus court, puis l\'ordre alphabétique. `first.slug` ou `second.slug`', example: 'tomate-0a1b2c3d')]
        public string $suggestedKeep,
    ) {
    }
}
