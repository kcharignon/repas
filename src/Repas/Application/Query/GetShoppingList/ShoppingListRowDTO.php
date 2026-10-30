<?php

namespace Repas\Repas\Application\Query\GetShoppingList;

use OpenApi\Attributes as OA;

final readonly class ShoppingListRowDTO
{
    public function __construct(
        #[OA\Property(description: 'Identifiant de la ligne', example: 'b2d1c0aa-52e4-4c8f-9d0e-0a6f0c5d7e11')]
        public string $id,
        #[OA\Property(example: 'œuf')]
        public string $ingredientName,
        #[OA\Property(description: 'Quantité à acheter, dans l\'unité d\'achat de l\'ingrédient', example: 6)]
        public float $quantity,
        #[OA\Property(description: 'Symbole de l\'unité (peut être vide)', example: 'g')]
        public string $unitSymbol,
        #[OA\Property(description: 'La ligne est cochée (déjà dans le caddie)')]
        public bool $checked,
        #[OA\Property(description: 'Identifiant stable de l\'ingrédient (slug)', example: 'oeuf')]
        public string $ingredientSlug,
        #[OA\Property(description: 'Identifiant stable de l\'unité d\'achat (slug)', example: 'gramme')]
        public string $unitSlug,
        #[OA\Property(description: 'Identifiant du rayon de l\'ingrédient (slug), le même que celui du rayon qui porte la ligne : la ligne seule (`GET …/rows/{id}`) le dit aussi. Le front en tire l\'icône par la liste des rayons', example: 'surgele')]
        public string $departmentSlug,
        #[OA\Property(description: 'Nom du rayon de l\'ingrédient', example: 'Surgelé')]
        public string $departmentName,
    ) {
    }
}
