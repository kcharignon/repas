<?php

namespace Repas\Repas\Infrastructure\Http\Api\CreateUnit;


use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de une unité, pour la création (`POST`) comme pour le remplacement complet (`PUT`).
 */
final readonly class UnitPayload
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        #[OA\Property(description: "Nom de l'unité (son slug en dérive et ne change plus)", example: 'Pincée')]
        public string $name,
        #[Assert\NotBlank]
        #[Assert\Length(max: 50)]
        #[OA\Property(description: "Symbole affiché", example: 'pin')]
        public string $symbol,
    ) {
    }
}
