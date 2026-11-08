<?php

namespace Repas\Repas\Application\Query\GetIngredient;

use OpenApi\Attributes as OA;

/**
 * Le parent d'un ingrédient qui est une PARTIE (ADR 0038) : « Œuf » pour « Jaune d'œuf ».
 */
final readonly class IngredientPartOfDTO
{
    public function __construct(
        #[OA\Property(description: 'Identifiant du parent (slug)', example: 'oeuf-0a1b2c3d')]
        public string $slug,
        #[OA\Property(example: 'Œuf')]
        public string $name,
        #[OA\Property(description: "Combien d'unités par défaut de cette partie font UNE unité de recette du parent (1 œuf = 1 jaune : 1)", example: 1)]
        public float $ratio,
    ) {
    }
}
