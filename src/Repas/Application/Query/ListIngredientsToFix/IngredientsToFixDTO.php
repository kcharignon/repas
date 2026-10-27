<?php

namespace Repas\Repas\Application\Query\ListIngredientsToFix;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * Les ingrédients à régler d'un utilisateur : `member` en donne les premiers (200 au plus), `totalItems` le vrai nombre.
 */
final readonly class IngredientsToFixDTO
{
    /**
     * @param list<IngredientToFixDTO> $member
     */
    public function __construct(
        #[OA\Property(description: 'Les ingrédients à régler, triés par nom (puis par identifiant), 200 au plus', type: 'array', items: new OA\Items(ref: new Model(type: IngredientToFixDTO::class)))]
        public array $member,
        #[OA\Property(description: 'Le nombre réel d\'ingrédients à régler (peut dépasser le nombre de `member`)', example: 3)]
        public int $totalItems,
    ) {
    }
}
