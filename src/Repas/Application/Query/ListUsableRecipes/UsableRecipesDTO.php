<?php

namespace Repas\Repas\Application\Query\ListUsableRecipes;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * Les recettes qu'un éditeur peut proposer comme recette utilisée (ADR 0040 décision 22).
 */
final readonly class UsableRecipesDTO
{
    /**
     * @param list<UsableRecipeDTO> $member
     */
    public function __construct(
        #[OA\Property(description: "Les recettes proposées, 200 au plus : sans `q` triées par nom puis par identifiant, avec `q` par pertinence décroissante (ADR 0019). Ni la recette elle-même, ni une recette qui a déjà ses propres composants (profondeur 1)", type: 'array', items: new OA\Items(ref: new Model(type: UsableRecipeDTO::class)))]
        public array $member,
        #[OA\Property(description: 'Le nombre réel de recettes proposables (ou de correspondances avec `q`) ; peut dépasser le nombre de `member`', example: 12)]
        public int $totalItems,
        #[OA\Property(description: "Faux quand la recette écrite (`forRecipeId`) est elle-même utilisée par une autre recette, ou n'a pas de propriétaire : elle ne peut pas avoir de recette utilisée (profondeur 1), le front masque alors la section. Toujours écrit ; vrai sans `forRecipeId`", example: true)]
        public bool $canHaveComponents,
    ) {
    }
}
