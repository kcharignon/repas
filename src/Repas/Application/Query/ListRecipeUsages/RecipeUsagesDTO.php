<?php

namespace Repas\Repas\Application\Query\ListRecipeUsages;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Query\ListRecipesByType\RecipeSummaryDTO;

/**
 * Les recettes qui en utilisent une autre (ADR 0040 décision 23) : de quoi dire à l'auteur pourquoi il ne peut pas la supprimer.
 */
final readonly class RecipeUsagesDTO
{
    /**
     * @param list<RecipeSummaryDTO> $member
     */
    public function __construct(
        #[OA\Property(description: "Les recettes DE L'AUTEUR qui utilisent cette recette, triées par nom puis par identifiant. Une recette secrète n'est montrée qu'à son auteur", type: 'array', items: new OA\Items(ref: new Model(type: RecipeSummaryDTO::class)))]
        public array $member,
        #[OA\Property(description: 'Le nombre de recettes de `member`', example: 2)]
        public int $totalItems,
    ) {
    }
}
