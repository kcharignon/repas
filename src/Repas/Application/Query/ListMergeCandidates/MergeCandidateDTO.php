<?php

namespace Repas\Repas\Application\Query\ListMergeCandidates;

use OpenApi\Attributes as OA;

final readonly class MergeCandidateDTO
{
    public function __construct(
        #[OA\Property(description: 'Identifiant de l\'ingrédient (slug)', example: 'tomate')]
        public string $slug,
        #[OA\Property(example: 'Tomate')]
        public string $name,
        #[OA\Property(description: 'Identifiant du rayon (slug)', example: 'fruit-et-legume')]
        public string $departmentSlug,
        #[OA\Property(example: 'Fruit & légume')]
        public string $departmentName,
        #[OA\Property(description: 'Nom de l\'unité d\'achat', example: 'kilo')]
        public string $purchaseUnitName,
        #[OA\Property(description: 'Nombre de recettes de l\'utilisateur qui utilisent cet ingrédient', example: 12)]
        public int $recipeCount,
        #[OA\Property(description: 'Nombre de listes de courses de l\'utilisateur qui utilisent cet ingrédient', example: 3)]
        public int $listCount,
        #[OA\Property(description: 'Le rayon est celui de l\'ingrédient qu\'on veut fusionner')]
        public bool $sameDepartment,
        #[OA\Property(description: 'Le nom est le même (casse, accents, pluriel) ou à une faute de frappe près : le meilleur choix probable')]
        public bool $veryClose,
    ) {
    }
}
