<?php

namespace Repas\Repas\Application\Query\ListOtherPeopleRecipeTypes;

use OpenApi\Attributes as OA;

final readonly class OtherPeopleRecipeTypeDTO
{
    public function __construct(
        #[OA\Property(description: 'Identifiant (slug) du type de recette ; à passer en `typeSlug` à `GET /recipes/other-people`', example: 'plat')]
        public string $typeSlug,
        #[OA\Property(example: 'plat')]
        public string $typeName,
        #[OA\Property(description: "Le nombre de recettes que l'utilisateur peut copier dans ce type : exactement le `totalItems` de `GET /recipes/other-people?typeSlug=…`", example: 12)]
        public int $count,
    ) {
    }
}
