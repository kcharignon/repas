<?php

namespace Repas\Shared\Application\Interface;

/**
 * Port qui dit à `Drive` dans quel rayon de Repas se range un ingrédient (le rayon qui groupe la liste de courses), au moment
 * où il enregistre un choix de produit : il en tire le rayon de l'enseigne où l'on cherche habituellement cet ingrédient
 * (DRV-642). Défini ici, implémenté dans `Repas\Infrastructure\Loader` (ADR 0013 règle 8 : `Drive` n'importe pas `Repas`).
 *
 * Il ne s'appelle volontairement pas `…Loader` : ce nom est réservé aux ports de lecture que seuls les `QueryHandler` et les
 * loaders d'infrastructure appellent (Deptrac `no-loader-in-commandhandler`), alors que le `SaveProductChoicesHandler` en a
 * besoin pendant une écriture. Comme `OwnershipLoader`, il ne renvoie que des identifiants, jamais un agrégat.
 */
interface IngredientDepartmentResolver
{
    /**
     * Une seule requête quel que soit le nombre d'ingrédients.
     *
     * @param list<string> $ingredientSlugs
     *
     * @return array<string, string> slug du rayon de Repas par slug d'ingrédient ; un ingrédient inconnu est absent du résultat
     */
    public function departmentSlugsByIngredient(array $ingredientSlugs): array;
}
