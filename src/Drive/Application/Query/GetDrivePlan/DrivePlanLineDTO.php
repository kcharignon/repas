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
        #[OA\Property(description: "Texte à chercher sur le site de l'enseigne : la recherche qui a fait trouver le produit choisi pour cet ingrédient (DRV-650, mémorisée pour cette enseigne et ce magasin, voir `searchQuery` de `GET /drive/choices`), sinon le nom de l'ingrédient. `ingredientName` reste le nom affiché", example: 'Farine')]
        public string $searchQuery,
        /** @var list<int> */
        #[OA\Property(
            description: "Rayons de l'enseigne où chercher d'abord cet ingrédient (DRV-642), 3 au plus, du plus choisi au moins choisi : ceux où les utilisateurs ont choisi des produits pour des ingrédients du même rayon de Repas. Ce sont des identifiants de rayon de l'enseigne (`iIdRayon` chez Leclerc), jamais des noms. Vide quand rien n'est encore appris pour ce rayon de Repas, ou quand l'ingrédient n'a pas de rayon. Un classement, pas un filtre : aucun produit n'est écarté. Clé facultative pour le lecteur (un back plus ancien ne l'envoie pas)",
            type: 'array',
            items: new OA\Items(type: 'integer', minimum: 1, maximum: 2147483647),
            maxItems: 3,
            example: [4001, 4002],
        )]
        public array $rayonHints = [],
    ) {
    }
}
