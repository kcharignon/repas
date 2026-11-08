<?php

namespace Repas\Repas\Application\Query\ListOtherPeopleRecipes;

use OpenApi\Attributes as OA;

final readonly class OtherPeopleRecipeDTO
{
    public function __construct(
        #[OA\Property(description: 'Identifiant de la recette', example: '3f1c2a7e-8d41-4c62-9b0a-5e7d2f6a1b34')]
        public string $id,
        #[OA\Property(example: 'pâtes carbonara')]
        public string $name,
        #[OA\Property(description: 'Nom du type de recette (la catégorie)', example: 'plat')]
        public string $typeName,
        #[OA\Property(description: 'Identifiant stable du type de recette, pour grouper les recettes par catégorie', example: 'plat')]
        public string $typeSlug,
        #[OA\Property(description: "Vrai pour une recette Marmiton neutre (ADR 0037 décision 11) : sans propriétaire, visible de tous, qu'on ne fait que copier ; le front en tire la pastille « Marmiton ». Toujours écrit", example: false)]
        public bool $fromMarmiton,
        #[OA\Property(description: "Vrai si l'utilisateur a déjà une copie de cette recette. Seul un administrateur reçoit une recette sans propriétaire déjà copiée (il la modifie ou la supprime) : pour lui, le front ne propose pas « Copier » en double. Toujours écrit ; toujours `false` pour un autre utilisateur, dont la liste ne contient que ce qu'il n'a pas copié", example: false)]
        public bool $alreadyCopied,
        #[OA\Property(description: "L'identifiant de la copie de l'administrateur (la plus ancienne s'il en a plusieurs), même renommée ou d'un autre type ; `null` s'il n'en a pas. `alreadyCopied` est vrai si et seulement si `copyId` n'est pas `null`. Toujours écrit ; toujours `null` pour un autre utilisateur", nullable: true, example: null)]
        public ?string $copyId = null,
    ) {
    }
}
