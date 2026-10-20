<?php

namespace Repas\Drive\Application\Query\ListChoices;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

final readonly class ChoiceDTO
{
    /**
     * @param list<AcceptedProductDTO> $accepted
     * @param list<ChoiceProductDTO> $rejected
     */
    public function __construct(
        #[OA\Property(description: "Slug de l'ingrédient", example: 'farine')]
        public string $ingredientSlug,
        #[OA\Property(description: "Nom de l'ingrédient ; son slug si l'ingrédient n'existe plus", example: 'Farine')]
        public string $ingredientName,
        #[OA\Property(description: "Vrai pour « j'en ai toujours » : la ligne est ignorée à l'envoi, `accepted` et `rejected` sont vides", example: false)]
        public bool $haveAtHome,
        #[OA\Property(description: "Produits acceptés, du préféré (rang 1) aux produits de secours ; 5 au plus", type: 'array', items: new OA\Items(ref: new Model(type: AcceptedProductDTO::class)))]
        public array $accepted,
        #[OA\Property(description: 'Produits que l\'utilisateur ne veut jamais ; 20 au plus', type: 'array', items: new OA\Items(ref: new Model(type: ChoiceProductDTO::class)))]
        public array $rejected,
        #[OA\Property(description: 'Dernière modification de la préférence (ISO 8601)', example: '2026-10-18T10:00:00+02:00')]
        public string $updatedAt,
        #[OA\Property(description: "Le texte qui a fait trouver le produit choisi sur le site de l'enseigne (DRV-650), propre à cette enseigne et à ce magasin : le plan le cherche en premier pour remplacer ce produit s'il n'est plus disponible. `null` quand rien n'est mémorisé (et toujours pour « j'en ai toujours »). Texte tapé par l'utilisateur : à afficher comme du texte, jamais comme du HTML", example: 'céréales coussins chocolat', nullable: true)]
        public ?string $searchQuery,
    ) {
    }
}
