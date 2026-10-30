<?php

namespace Repas\Drive\Domain\Model;


/**
 * Le verdict de la règle de choix de l'enseigne (`RetailerSelection`, DRV-703) : le mode, l'enseigne retenue (ou présélectionnée en
 * `ASK`, `null` en `NONE`) et les enseignes candidates (allumées ET configurées), la plus anciennement enregistrée en tête.
 */
final readonly class Selection
{
    /**
     * @param list<Retailer> $candidates
     */
    public function __construct(
        public SelectionMode $mode,
        public ?Retailer $retailer,
        public array $candidates,
    ) {
    }
}
