<?php

namespace Repas\Repas\Application\Query\ListOtherPeopleRecipes;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * Les recettes des autres que l'utilisateur n'a pas copiées : `member` en donne une page (200 au plus), `totalItems` le vrai nombre.
 */
final readonly class OtherPeopleRecipesDTO
{
    /**
     * @param list<OtherPeopleRecipeDTO> $member
     */
    public function __construct(
        #[OA\Property(description: 'Les recettes de cette page, triées par slug, 200 au plus', type: 'array', items: new OA\Items(ref: new Model(type: OtherPeopleRecipeDTO::class)))]
        public array $member,
        #[OA\Property(description: 'Le nombre réel de recettes des autres, toutes pages confondues (peut dépasser le nombre de `member`)', example: 12)]
        public int $totalItems,
    ) {
    }
}
