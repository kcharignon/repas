<?php

namespace Repas\Repas\Application\Query\GetIngredient;

use OpenApi\Attributes as OA;

final readonly class IngredientDTO
{
    public function __construct(
        #[OA\Property(description: 'Identifiant de l\'ingrédient (slug)', example: 'oeuf')]
        public string $slug,
        #[OA\Property(example: 'œuf')]
        public string $name,
        #[OA\Property(description: 'Chemin ou URL de l\'image (peut être vide)', example: 'images/ingredient/egg.png')]
        public string $image,
        #[OA\Property(description: 'Identifiant du rayon (slug)', example: 'divers')]
        public string $departmentSlug,
        #[OA\Property(example: 'divers')]
        public string $departmentName,
        #[OA\Property(description: 'Identifiant de l\'unité des recettes (slug)', example: 'unite')]
        public string $cookingUnitSlug,
        #[OA\Property(example: 'unité')]
        public string $cookingUnitName,
        #[OA\Property(description: 'Identifiant de l\'unité d\'achat (slug)', example: 'unite')]
        public string $purchaseUnitSlug,
        #[OA\Property(example: 'unité')]
        public string $purchaseUnitName,
        #[OA\Property(description: 'Symbole de l\'unité d\'achat (peut être vide)', example: '')]
        public string $purchaseUnitSymbol,
        #[OA\Property(description: 'Les unités des recettes et d\'achat sont les mêmes (aucune conversion)')]
        public bool $hasSameUnit,
        #[OA\Property(description: 'Combien d\'unités des recettes contient une unité d\'achat ; null si les unités sont les mêmes ou sans conversion connue', example: 250, nullable: true)]
        public ?float $coefficient,
        #[OA\Property(description: 'Identifiant du créateur, null pour un ingrédient commun', nullable: true)]
        public ?string $creatorId,
        #[OA\Property(description: 'L\'utilisateur peut modifier cet ingrédient : le sien, ou un ingrédient commun s\'il est administrateur')]
        public bool $canEdit,
    ) {
    }
}
