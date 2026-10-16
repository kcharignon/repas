<?php

namespace Repas\Repas\Application\Query\SearchIngredients;

use OpenApi\Attributes as OA;

final readonly class IngredientSearchResultDTO
{
    public function __construct(
        #[OA\Property(description: 'Identifiant de l\'ingrédient (slug)', example: 'oeuf')]
        public string $slug,
        #[OA\Property(example: 'œuf')]
        public string $name,
        #[OA\Property(description: 'Symbole de l\'unité d\'achat (peut être vide)', example: 'g')]
        public string $purchaseUnitSymbol,
        #[OA\Property(description: 'L\'utilisateur peut modifier cet ingrédient : le sien, ou un ingrédient commun s\'il est administrateur')]
        public bool $canEdit,
        #[OA\Property(description: 'Identifiant du rayon de l\'ingrédient (slug)', example: 'cremerie')]
        public string $departmentSlug,
        #[OA\Property(description: 'Nom du rayon de l\'ingrédient', example: 'Crèmerie')]
        public string $departmentName,
        #[OA\Property(description: 'Chemin ou URL de l\'image du rayon de l\'ingrédient', example: 'images/department/cremerie.png')]
        public string $departmentImage,
    ) {
    }
}
