<?php

namespace Repas\Drive\Application\Command\SaveProductChoices;


use Repas\Drive\Domain\Model\ProductContent;
use Repas\Drive\Domain\Model\ProductPrice;

/**
 * Un produit tel que l'agent l'a lu chez l'enseigne. Le libellé est exigé pour `ADD_PRODUCT` et `REJECT_PRODUCT` ; un
 * `REFRESH_PRICE` ne porte que l'identifiant et le prix. Le rayon et la famille de l'enseigne (DRV-642) sont facultatifs : un
 * agent qui ne les connaît pas n'en envoie pas, et l'absence n'efface jamais une valeur déjà connue de la fiche.
 *
 * La contenance dans l'unité de la liste (`packSize`, DRV-652) n'est PAS un fait de la fiche du produit : c'est la réponse de la
 * personne, privée, mémorisée avec sa préférence. Elle n'est permise que sur `ADD_PRODUCT` ; `null` (pas de changement) garde celle
 * qui est mémorisée pour ce produit, `PackSizeChange::clear()` l'efface, `PackSizeChange::set()` la remplace.
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
        public ?PackSizeChange $packSize = null,
    ) {
    }
}
