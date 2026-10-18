<?php

namespace Repas\Drive\Application\Query\GetDrivePlan;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

final readonly class DrivePlanLineDTO
{
    /**
     * @param list<DrivePlanProductDTO> $products
     * @param list<DrivePlanRejectedDTO> $rejected
     */
    public function __construct(
        #[OA\Property(description: "Slug de l'ingrédient", example: 'farine')]
        public string $ingredientSlug,
        #[OA\Property(description: "Nom de l'ingrédient", example: 'Farine')]
        public string $ingredientName,
        public DrivePlanNeededDTO $needed,
        #[OA\Property(description: '`KNOWN` : au moins un produit accepté dans ce magasin ; `NEW` : aucun, l\'utilisateur en choisira un ; `HAVE_AT_HOME` : « j\'en ai toujours », ligne ignorée', enum: ['KNOWN', 'NEW', 'HAVE_AT_HOME'], example: 'KNOWN')]
        public string $status,
        #[OA\Property(description: 'Produits acceptés, du préféré (rang 1) aux produits de secours, 5 au plus, chacun avec son propre nombre de produits ; vide hors `KNOWN`', type: 'array', items: new OA\Items(ref: new Model(type: DrivePlanProductDTO::class)))]
        public array $products,
        #[OA\Property(description: "Produits que l'utilisateur ne veut jamais, 20 au plus (aussi pour une ligne `NEW`) ; vide pour `HAVE_AT_HOME`", type: 'array', items: new OA\Items(ref: new Model(type: DrivePlanRejectedDTO::class)))]
        public array $rejected,
        #[OA\Property(description: "Texte à chercher sur le site de l'enseigne : le nom de l'ingrédient", example: 'Farine')]
        public string $searchQuery,
    ) {
    }
}
