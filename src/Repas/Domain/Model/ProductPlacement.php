<?php

namespace Repas\Repas\Domain\Model;


/**
 * Où et en quelle unité un produit d'un utilisateur se pose dans une liste de courses (ADR 0036 décision 3) : le type de la liste visée
 * (`null` = la liste principale) et l'unité d'achat de la ligne qu'il y crée. Une lecture, rendue par `ListRoutingRepository` : ajouter ou
 * retirer UN produit n'a besoin de ces deux faits qu'ensemble, et les poser dans une même requête évite d'en lire une de plus.
 *
 * Une PARTIE d'ingrédient (ADR 0038 décision 10) n'est jamais une ligne : elle va dans la liste de son parent et la ligne qu'elle crée est celle du
 * parent. Son placement dit donc la destination et l'unité d'achat DU PARENT, et `partOf` le slug de ce parent (`null` pour un ingrédient ordinaire,
 * et pour une partie dont le parent n'est plus à l'utilisateur : elle se pose alors comme un produit ordinaire).
 */
final readonly class ProductPlacement
{
    public function __construct(
        public ?string $listTypeId,
        public string $purchaseUnitSlug,
        public ?string $partOf = null,
    ) {
    }
}
