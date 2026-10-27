<?php

namespace Repas\Repas\Application\Query\ListIngredientConversions;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * Les conversions d'un ingrédient de l'utilisateur : les siennes (qu'il peut ajouter, modifier, retirer) et les universelles qui touchent ses
 * unités (en lecture seule : seul l'administrateur les écrit).
 */
final readonly class IngredientConversionsDTO
{
    /**
     * @param list<IngredientConversionDTO> $member
     * @param list<IngredientConversionDTO> $common
     */
    public function __construct(
        #[OA\Property(description: 'Les conversions PROPRES de l\'ingrédient, triées par nom d\'unité de départ puis d\'arrivée', type: 'array', items: new OA\Items(ref: new Model(type: IngredientConversionDTO::class)))]
        public array $member,
        #[OA\Property(description: 'Les conversions UNIVERSELLES (communes à tous les ingrédients, lecture seule) dont au moins une unité est celle de l\'ingrédient (ses unités des recettes et d\'achat, ou une unité de ses conversions propres)', type: 'array', items: new OA\Items(ref: new Model(type: IngredientConversionDTO::class)))]
        public array $common,
        #[OA\Property(description: 'Nombre de conversions propres (`member`)', example: 2)]
        public int $totalItems,
        #[OA\Property(description: 'L\'unité des recettes de l\'ingrédient se convertit en son unité d\'achat (toujours vrai pour un ingrédient enregistré : retirer ou changer la conversion qui le garantit est refusé)', example: true)]
        public bool $convertible,
        #[OA\Property(description: 'Unité des recettes de l\'ingrédient (slug)', example: 'cuillere-a-soupe')]
        public string $cookingUnitSlug,
        #[OA\Property(description: 'Unité d\'achat de l\'ingrédient (slug)', example: 'kilo')]
        public string $purchaseUnitSlug,
    ) {
    }
}
