<?php

namespace Repas\Shared\Application\Interface;

/**
 * Port de lecture du nom des ingrédients pour le contexte `Drive` (écran des produits mémorisés).
 *
 * Un nom ne se lit que pour les ingrédients de l'utilisateur qui le demande (ADR 0028).
 */
interface IngredientNameLoader
{
    /**
     * @param list<string> $slugs
     *
     * @return array<string, string> nom par slug ; un slug inconnu, ou qui désigne l'ingrédient d'un autre utilisateur, est absent du résultat
     */
    public function namesBySlug(string $userId, array $slugs): array;
}
