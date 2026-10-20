<?php

namespace Repas\Repas\Infrastructure\Http\Api\MarkIngredientsDistinct;


use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de « Pas le même » : l'autre ingrédient de la paire (l'un est dans l'URL, l'ordre est sans importance).
 */
final readonly class MarkIngredientsDistinctPayload
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        #[OA\Property(description: "Slug de l'autre ingrédient de la paire", example: 'tomates-0a1b2c3d', maxLength: 255)]
        public string $other,
    ) {
    }
}
