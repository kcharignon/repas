<?php

namespace Repas\Repas\Application\OpenHostService;

use Repas\Repas\Application\Loader\IngredientNameLoader;

/**
 * Open Host Service de lecture du contexte `Repas` (ADR 0035) : le nom des ingrédients d'un utilisateur, pour les écrans d'un autre
 * contexte qui montre des ingrédients par leur slug (les produits mémorisés du drive). Un nom ne se lit que pour les ingrédients de
 * l'utilisateur qui le demande (ADR 0028).
 */
final readonly class IngredientNameQueryOHS
{
    public function __construct(
        private IngredientNameLoader $names,
    ) {
    }

    /**
     * @param list<string> $slugs
     *
     * @return array<string, string> nom par slug ; un slug inconnu, ou qui désigne l'ingrédient d'un autre utilisateur, est absent du résultat
     */
    public function namesBySlug(string $userId, array $slugs): array
    {
        return $this->names->namesBySlug($userId, $slugs);
    }
}
