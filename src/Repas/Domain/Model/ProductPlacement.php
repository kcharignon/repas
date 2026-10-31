<?php

namespace Repas\Repas\Domain\Model;


/**
 * Où et en quelle unité un produit d'un utilisateur se pose dans une liste de courses (ADR 0036 décision 3) : le type de la liste visée
 * (`null` = la liste principale) et l'unité d'achat de la ligne qu'il y crée. Une lecture, rendue par `ListRoutingRepository` : ajouter ou
 * retirer UN produit n'a besoin de ces deux faits qu'ensemble, et les poser dans une même requête évite d'en lire une de plus.
 */
final readonly class ProductPlacement
{
    public function __construct(
        public ?string $listTypeId,
        public string $purchaseUnitSlug,
    ) {
    }
}
