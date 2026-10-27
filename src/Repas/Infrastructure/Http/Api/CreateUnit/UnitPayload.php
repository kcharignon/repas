<?php

namespace Repas\Repas\Infrastructure\Http\Api\CreateUnit;


use OpenApi\Attributes as OA;
use Repas\Repas\Domain\Model\QuantityBounds;
use Repas\Repas\Infrastructure\Http\PayloadMessages;
use Repas\Shared\Domain\Model\Dimension;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de une unité, pour la création (`POST`) comme pour le remplacement (`PUT`).
 *
 * `dimension` et `baseFactor` vont ensemble (les deux renseignés ou les deux `null`, sinon `422`). Au `PUT`, un corps qui
 * ne mentionne ni l'un ni l'autre laisse les valeurs de l'unité telles quelles ; un corps qui en mentionne un remplace
 * la paire (un champ absent compte comme `null`), et `null`, `null` l'efface.
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
        #[OA\Property(
            description: "Ce que l'unité mesure : `WEIGHT` (poids), `VOLUME`, `COUNT` (pièces) ou `PACKAGE` (conditionnement). `null` : dimension inconnue (cuillère, tranche…). Va avec `baseFactor`.",
            example: 'WEIGHT',
            nullable: true,
        )]
        public ?Dimension $dimension = null,
        #[Assert\GreaterThanOrEqual(QuantityBounds::MIN_COEFFICIENT, message: PayloadMessages::BASE_FACTOR)]
        #[Assert\LessThanOrEqual(QuantityBounds::MAX_COEFFICIENT, message: PayloadMessages::BASE_FACTOR)]
        #[OA\Property(
            description: "Combien d'unités de base (g, ml, pièce) vaut une unité, de 0,000001 à 1 000 000 : 1000 pour le kilo. Va avec `dimension` ; sans importance pour `PACKAGE` (1).",
            example: 1000,
            nullable: true,
            minimum: 0.000001,
            maximum: 1000000,
        )]
        public ?float $baseFactor = null,
    ) {
    }
}
