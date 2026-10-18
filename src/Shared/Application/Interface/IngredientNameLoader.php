<?php

namespace Repas\Shared\Application\Interface;

/**
 * Port de lecture du nom des ingrédients pour le contexte `Drive` (écran des produits mémorisés).
 */
interface IngredientNameLoader
{
    /**
     * @param list<string> $slugs
     *
     * @return array<string, string> nom par slug ; un slug inconnu est absent du résultat
     */
    public function namesBySlug(array $slugs): array;
}
