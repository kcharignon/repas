<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Model\ProductPlacement;

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

    /**
     * La même lecture, avec l'unité d'achat de chaque produit : de quoi poser (ou retirer) un produit dans une liste sans une requête de plus.
     * Contrairement à `destinationsFor`, un produit inconnu ou à un autre utilisateur n'a PAS d'entrée : l'appelant décide (404 pour un ajout).
     *
     * @param list<string> $slugs des slugs d'ingrédients de l'utilisateur, déjà traduits par les alias
     *
     * @return array<string, ProductPlacement> seulement les produits de l'utilisateur
     */
    public function placementsFor(string $ownerId, array $slugs): array;
}
