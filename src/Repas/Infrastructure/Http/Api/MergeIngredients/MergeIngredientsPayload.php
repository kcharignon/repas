<?php

namespace Repas\Repas\Infrastructure\Http\Api\MergeIngredients;


use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de la fusion de deux ingrédients : l'ingrédient de l'URL est absorbé, celui-ci est gardé.
 */
final readonly class MergeIngredientsPayload
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        #[OA\Property(description: "Slug de l'ingrédient à garder : celui de l'URL disparaît et tout ce qui l'utilisait utilise celui-ci", example: 'tomate-0a1b2c3d', maxLength: 255)]
        public string $into,
    ) {
    }
}
