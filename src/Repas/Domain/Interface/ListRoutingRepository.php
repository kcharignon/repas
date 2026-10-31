<?php

namespace Repas\Repas\Domain\Interface;


/**
 * Dans quelle liste de courses va chaque produit d'un utilisateur (ADR 0036 décision 3, `ListDestinationResolver`). Une lecture, jamais
 * une écriture : l'ajout d'un produit ou d'une recette (LST-005) la pose avant d'écrire.
 */
interface ListRoutingRepository
{
    /**
     * La destination de chacun de ces produits de l'utilisateur, pour N produits en UNE SEULE lecture (aucune quand la liste est vide) :
     * le choix fait sur le produit, sinon le type de son rayon, sinon la liste principale.
     *
     * @param list<string> $slugs des slugs d'ingrédients de l'utilisateur, déjà traduits par les alias (`IngredientSlugResolver`)
     *
     * @return array<string, string|null> par slug demandé (sans exception : un produit inconnu ou à un autre va dans la liste principale),
     *                                    `null` = la liste principale, sinon l'identifiant du type de liste
     */
    public function destinationsFor(string $ownerId, array $slugs): array;
}
