<?php

namespace Repas\Repas\Infrastructure\Http\Api\PlannedMeal;

use OpenApi\Attributes as OA;

/**
 * Une liste qui a reçu un repas de la recette planifiée (corps de `PUT /api/v1/shopping-lists/active/recipes/{recipeId}`).
 */
final readonly class PlannedMealListResponse
{
    public function __construct(
        #[OA\Property(description: 'Identifiant de la liste', example: '0b0a8c4e-6b0e-4e0a-9d52-2c3f7a1d9e10')]
        public string $id,
        #[OA\Property(description: 'Nom de la liste (celui du type pour une liste de type), ou à défaut sa date de création en français', example: 'Boucherie')]
        public string $name,
        #[OA\Property(description: 'Identifiant du type de la liste (ADR 0036), null pour la liste principale', example: null, nullable: true)]
        public ?string $typeId,
    ) {
    }
}
