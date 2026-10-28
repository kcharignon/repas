<?php

namespace Repas\Repas\Application\Query\ListOtherPeopleRecipes;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * Les recettes des autres que l'utilisateur n'a pas copiées (ou, avec une recherche, celles dont le nom correspond) : `member` en donne une page (200 au plus), `totalItems` le vrai nombre.
 */
final readonly class OtherPeopleRecipesDTO
{
    /**
     * @param list<OtherPeopleRecipeDTO> $member
     */
    public function __construct(
        #[OA\Property(description: "Les recettes de cette page, 200 au plus : sans recherche triées par catégorie (l'ordre des types) puis par nom, avec une recherche par pertinence décroissante, toutes catégories confondues", type: 'array', items: new OA\Items(ref: new Model(type: OtherPeopleRecipeDTO::class)))]
        public array $member,
        #[OA\Property(description: 'Le nombre réel de recettes des autres (ou de correspondances, avec une recherche), toutes pages confondues ; peut dépasser le nombre de `member`', example: 12)]
        public int $totalItems,
    ) {
    }
}
