<?php

namespace Repas\Drive\Application\Command\SaveProductChoices;


use Repas\Drive\Domain\Model\ProductContent;
use Repas\Drive\Domain\Model\ProductPrice;

/**
 * Un produit tel que l'agent l'a lu chez l'enseigne. Le libellé est exigé pour `ADD_PRODUCT` et `REJECT_PRODUCT` ; un
 * `REFRESH_PRICE` ne porte que l'identifiant et le prix. Le rayon et la famille de l'enseigne (DRV-642) sont facultatifs : un
 * agent qui ne les connaît pas n'en envoie pas, et l'absence n'efface jamais une valeur déjà connue de la fiche.
 */
final readonly class ProductInput
{
    public function __construct(
        public string $retailerProductId,
        public ?string $label = null,
        public ?string $ean = null,
        public ?ProductContent $content = null,
        public ?ProductPrice $price = null,
        public ?int $rayonId = null,
        public ?int $familyId = null,
    ) {
    }
}
