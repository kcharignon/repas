<?php

namespace Repas\Repas\Application\Query\GetRecipe;

use OpenApi\Attributes as OA;

final readonly class RecipeRowDTO
{
    public function __construct(
        #[OA\Property(description: 'Identifiant de l\'ingrédient (slug)', example: 'oeuf')]
        public string $ingredientSlug,
        #[OA\Property(example: 'œuf')]
        public string $ingredientName,
        #[OA\Property(description: 'Chemin ou URL de l\'image de l\'ingrédient (peut être vide)', example: 'images/ingredient/egg.png')]
        public string $ingredientImage,
        #[OA\Property(description: 'Quantité pour le nombre de personnes de la recette', example: 4)]
        public float $quantity,
        #[OA\Property(description: 'Identifiant de l\'unité (slug)', example: 'unite')]
        public string $unitSlug,
        #[OA\Property(description: 'Symbole de l\'unité (peut être vide)', example: 'g')]
        public string $unitSymbol,
    ) {
    }
}
