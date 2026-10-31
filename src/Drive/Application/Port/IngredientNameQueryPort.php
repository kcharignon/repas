<?php

namespace Repas\Drive\Application\Port;

/**
 * Ce que l'écran des produits mémorisés demande aux ingrédients : leur nom. Un nom ne se lit que pour les ingrédients de l'utilisateur qui le
 * demande (ADR 0028). Adapté depuis le contexte `Repas` (ADR 0035).
 */
interface IngredientNameQueryPort
{
    /**
     * @param list<string> $slugs
     *
     * @return array<string, string> nom par slug ; un slug inconnu, ou qui désigne l'ingrédient d'un autre utilisateur, est absent du résultat
     */
    public function namesBySlug(string $userId, array $slugs): array;
}
