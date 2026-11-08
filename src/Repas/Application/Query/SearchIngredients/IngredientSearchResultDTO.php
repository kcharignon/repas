<?php

namespace Repas\Repas\Application\Query\SearchIngredients;

use OpenApi\Attributes as OA;

final readonly class IngredientSearchResultDTO
{
    public function __construct(
        #[OA\Property(description: 'Identifiant de l\'ingrédient (slug)', example: 'oeuf')]
        public string $slug,
        #[OA\Property(example: 'œuf')]
        public string $name,
        #[OA\Property(description: 'Symbole de l\'unité d\'achat (peut être vide)', example: 'g')]
        public string $purchaseUnitSymbol,
        #[OA\Property(description: 'Toujours vrai : l\'ingrédient est à l\'utilisateur. Le champ reste pour les clients existants')]
        public bool $canEdit,
        #[OA\Property(description: 'Identifiant du rayon de l\'ingrédient (slug)', example: 'cremerie')]
        public string $departmentSlug,
        #[OA\Property(description: 'Nom du rayon de l\'ingrédient', example: 'Crèmerie')]
        public string $departmentName,
        #[OA\Property(description: 'Chemin ou URL de l\'image du rayon de l\'ingrédient', example: 'images/department/cremerie.png')]
        public string $departmentImage,
        #[OA\Property(description: "Identifiant du parent quand l'ingrédient est une PARTIE d'un autre (ADR 0038 : « Jaune d'œuf » pour « Œuf »), `null` pour un ingrédient ordinaire. Clé toujours écrite", type: 'string', nullable: true, example: 'oeuf-0a1b2c3d')]
        public ?string $partOfSlug,
        #[OA\Property(description: "Nom du parent quand l'ingrédient est une PARTIE d'un autre, `null` pour un ingrédient ordinaire. Clé toujours écrite", type: 'string', nullable: true, example: 'Œuf')]
        public ?string $partOfName,
    ) {
    }
}
