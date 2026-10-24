<?php

namespace Repas\Drive\Infrastructure\Http\Api\SaveProductChoices;


use OpenApi\Attributes as OA;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Drive\Domain\Model\PackSize;

final readonly class PackSizePayload
{
    public function __construct(
        #[OA\Property(description: "Combien d'unités de la liste tiennent dans le produit : de 0,001 à 9 999, trois décimales au plus", minimum: 0.001, maximum: 9999, example: 20)]
        public float $value,
        #[OA\Property(description: "Slug de l'unité de la liste dans laquelle la valeur est comptée (`needed.unitSlug` de la ligne du plan) : une unité qui existe dans Repas", pattern: '^[a-z0-9-]{1,255}$', example: 'tranche')]
        public string $unitSlug,
    ) {
    }

    /**
     * @throws DriveException `packSizeInvalid()` (422) pour une valeur hors des bornes ou un slug mal formé
     */
    public function toPackSize(): PackSize
    {
        return PackSize::of($this->value, $this->unitSlug);
    }
}
