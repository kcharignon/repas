<?php

namespace Repas\Repas\Infrastructure\Http\Api\RemoveRecipeType;


use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Paramètres de la suppression d'un type de recette : le type qui reçoit ses recettes.
 */
final readonly class RemoveRecipeTypeRequestQuery
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        #[OA\Property(description: 'Slug du type de recette qui reçoit toutes les recettes du type supprimé (différent de celui-ci)', example: 'plat')]
        public string $transferTo,
    ) {
    }
}
