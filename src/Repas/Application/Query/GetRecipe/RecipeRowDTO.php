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
        #[OA\Property(description: 'Nom de l\'unité de la ligne', example: 'cuillère à soupe')]
        public string $unitName,
        #[OA\Property(description: 'Identifiant de l\'unité d\'achat de l\'ingrédient (slug)', example: 'kilo')]
        public string $purchaseUnitSlug,
        #[OA\Property(description: 'Nom de l\'unité d\'achat de l\'ingrédient', example: 'kilo')]
        public string $purchaseUnitName,
        #[OA\Property(description: 'L\'unité de la ligne se convertit en l\'unité d\'achat de l\'ingrédient (ses conversions propres et les universelles, comme à la planification) ; faux : la recette ne peut pas être planifiée tant que la conversion manque', example: true)]
        public bool $convertible,
        #[OA\Property(description: 'Identifiant du rayon de l\'ingrédient (slug) : deux ingrédients de même nom dans deux rayons (ADR 0028, amendement du 2026-10-30) se distinguent par lui ; le front en tire l\'icône par la liste des rayons', example: 'surgele')]
        public string $departmentSlug,
        #[OA\Property(description: 'Nom du rayon de l\'ingrédient', example: 'Surgelé')]
        public string $departmentName,
    ) {
    }
}
