<?php

namespace Repas\Drive\Infrastructure\Http\Api\SaveProductChoices;


use OpenApi\Attributes as OA;
use Repas\Drive\Domain\Model\PricePerMeasure;
use Repas\Drive\Domain\Model\PriceUnit;

final readonly class PricePerMeasurePayload
{
    public function __construct(
        #[OA\Property(description: "Prix TTC de l'unité de mesure en centimes : de 0 à 1 000 000", example: 249)]
        public int $cents,
        #[OA\Property(description: "Unité de mesure : le kilo, le litre ou la pièce", enum: ['kg', 'l', 'piece'], example: 'kg')]
        public PriceUnit $unit,
    ) {
    }

    public function toPricePerMeasure(): PricePerMeasure
    {
        return new PricePerMeasure($this->cents, $this->unit);
    }
}
