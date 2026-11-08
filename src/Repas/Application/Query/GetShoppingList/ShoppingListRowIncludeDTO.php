<?php

namespace Repas\Repas\Application\Query\GetShoppingList;

use OpenApi\Attributes as OA;

/**
 * Une PARTIE d'ingrédient que la ligne d'un parent contient (ADR 0038 décision 15) : « 2 jaunes d'œuf » dans la ligne « Œuf 5 ». C'est la quantité
 * de la partie telle que la liste l'a reçue, pas son équivalent en parent.
 */
final readonly class ShoppingListRowIncludeDTO
{
    public function __construct(
        #[OA\Property(description: 'Nom de la partie', example: "Jaune d'œuf")]
        public string $name,
        #[OA\Property(description: 'Quantité de la partie dans la liste, dans l\'unité d\'achat de la partie', example: 2)]
        public float $quantity,
        #[OA\Property(description: 'Symbole de l\'unité d\'achat de la partie (peut être vide)', example: '')]
        public string $unitSymbol,
    ) {
    }
}
