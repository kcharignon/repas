<?php

namespace Repas\Drive\Infrastructure\Http\Api\SaveProductChoices;


use OpenApi\Attributes as OA;
use Repas\Drive\Domain\Model\ContentUnit;
use Repas\Drive\Domain\Model\ProductContent;

final readonly class ProductContentPayload
{
    public function __construct(
        #[OA\Property(description: 'Quantité contenue : de 0,001 à 100 000', example: 1000)]
        public float $value,
        #[OA\Property(description: 'Unité du contenu', enum: ['g', 'kg', 'ml', 'cl', 'l', 'piece'], example: 'g')]
        public ContentUnit $unit,
    ) {
    }

    public function toContent(): ProductContent
    {
        return new ProductContent($this->value, $this->unit);
    }
}
