<?php

namespace Repas\Repas\Application\Query\GetActiveShoppingListSummary;

use OpenApi\Attributes as OA;

final readonly class ActiveShoppingListSummaryDTO
{
    /**
     * @param array<string, float> $quantityByIngredientSlug
     * @param list<string> $plannedRecipeIds
     */
    public function __construct(
        #[OA\Property(description: 'Identifiant de la liste active, null si l\'utilisateur n\'en a pas', example: '0b0a8c4e-6b0e-4e0a-9d52-2c3f7a1d9e10', nullable: true)]
        public ?string $shoppingListId,
        #[OA\Property(
            description: 'Quantité de chaque ingrédient de la liste, dans son unité d\'achat, indexée par slug d\'ingrédient',
            type: 'object',
            additionalProperties: new OA\AdditionalProperties(type: 'number'),
            example: ['oeuf' => 6, 'farine' => 0.5],
        )]
        public array $quantityByIngredientSlug,
        #[OA\Property(
            description: 'Identifiants des recettes planifiées dans la liste',
            type: 'array',
            items: new OA\Items(type: 'string'),
            example: ['3f1c2a7e-8d41-4c62-9b0a-5e7d2f6a1b34'],
        )]
        public array $plannedRecipeIds,
    ) {
    }
}
