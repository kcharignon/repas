<?php

namespace Repas\Repas\Application\Query\ListIngredientsByDepartment;

use OpenApi\Attributes as OA;

final readonly class IngredientRowDTO
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
    ) {
    }
}
