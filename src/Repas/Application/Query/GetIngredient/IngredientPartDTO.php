<?php

namespace Repas\Repas\Application\Query\GetIngredient;

use OpenApi\Attributes as OA;

/**
 * Une PARTIE de l'ingrédient (ADR 0038) : « Jaune d'œuf » pour « Œuf ». La fiche du parent les montre, `PUT …/parent` sur la partie les écrit.
 */
final readonly class IngredientPartDTO
{
    public function __construct(
        #[OA\Property(description: 'Identifiant de la partie (slug)', example: 'jaune-d-oeuf-0a1b2c3d')]
        public string $slug,
        #[OA\Property(example: "Jaune d'œuf")]
        public string $name,
        #[OA\Property(description: "Combien d'unités par défaut de la partie font UNE unité de recette du parent (1 œuf = 1 jaune : 1)", example: 1)]
        public float $ratio,
        #[OA\Property(description: "Nom de l'unité d'achat de la partie (celle dont parle le ratio)", example: 'unité')]
        public string $unitName,
    ) {
    }
}
