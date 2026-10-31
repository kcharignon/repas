<?php

namespace Repas\Repas\Application\OpenHostService;

use Repas\Repas\Domain\Interface\IngredientRepository;

/**
 * Open Host Service d'écriture du contexte `Repas` (ADR 0035) : dans quel rayon se range un ingrédient (le rayon qui groupe la liste de
 * courses), pour un appelant qui écrit et en tire un apprentissage (DRV-642). Des identifiants seulement, jamais un agrégat.
 */
final readonly class IngredientDepartmentCommandOHS
{
    public function __construct(
        private IngredientRepository $ingredients,
    ) {
    }

    /**
     * Une seule requête quel que soit le nombre d'ingrédients.
     *
     * @param list<string> $ingredientSlugs
     *
     * @return array<string, string> slug du rayon par slug d'ingrédient ; un ingrédient inconnu est absent du résultat
     */
    public function departmentSlugsByIngredient(array $ingredientSlugs): array
    {
        return $this->ingredients->departmentSlugsOf($ingredientSlugs);
    }
}
