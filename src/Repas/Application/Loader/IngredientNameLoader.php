<?php

namespace Repas\Repas\Application\Loader;

/**
 * Lecture du nom des ingrédients d'un utilisateur, par slug. Un nom ne se lit que pour les ingrédients de l'utilisateur qui le demande
 * (ADR 0028).
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
