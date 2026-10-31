<?php

namespace Repas\Drive\Application\Port;

/**
 * Ce que l'enregistrement d'un choix de produit demande aux ingrédients : dans quel rayon se range un ingrédient (le rayon qui groupe la liste
 * de courses), pour en tirer le rayon de l'enseigne où l'on cherche habituellement cet ingrédient (DRV-642). Ne renvoie que des identifiants,
 * jamais un agrégat. Adapté depuis le contexte `Repas` (ADR 0035).
 */
interface IngredientDepartmentCommandPort
{
    /**
     * Une seule requête quel que soit le nombre d'ingrédients.
     *
     * @param list<string> $ingredientSlugs
     *
     * @return array<string, string> slug du rayon par slug d'ingrédient ; un ingrédient inconnu est absent du résultat
     */
    public function departmentSlugsByIngredient(array $ingredientSlugs): array;
}
