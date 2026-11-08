<?php

namespace Repas\Repas\Application\Query\ListOtherPeopleRecipeTypes;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * Les catégories de recettes des autres, avec le nombre de recettes que l'utilisateur peut copier dans chacune (celles qui en ont au moins une).
 */
final readonly class OtherPeopleRecipeTypesDTO
{
    /**
     * @param list<OtherPeopleRecipeTypeDTO> $member
     */
    public function __construct(
        #[OA\Property(description: "Les catégories qui ont au moins une recette à copier, dans l'ordre des types de recette", type: 'array', items: new OA\Items(ref: new Model(type: OtherPeopleRecipeTypeDTO::class)))]
        public array $member,
        #[OA\Property(description: 'Le nombre de catégories de `member`', example: 3)]
        public int $totalItems,
    ) {
    }
}
