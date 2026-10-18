<?php

namespace Repas\Drive\Infrastructure\Http\Api\SaveProductChoices;


use OpenApi\Attributes as OA;
use Repas\Drive\Domain\Model\ProductPrice;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ProductPricePayload
{
    public function __construct(
        #[OA\Property(description: 'Prix unitaire TTC en centimes : de 0 à 1 000 000', example: 249)]
        public int $cents,
        #[Assert\Valid]
        #[OA\Property(description: "Prix à l'unité de mesure, `null` si le site ne le donne pas", nullable: true)]
        public ?PricePerMeasurePayload $perMeasure = null,
    ) {
    }

    /**
     * @throws \Repas\Drive\Domain\Exception\DriveException prix hors des bornes
     */
    public function toPrice(): ProductPrice
    {
        return new ProductPrice($this->cents, $this->perMeasure?->toPricePerMeasure());
    }
}
