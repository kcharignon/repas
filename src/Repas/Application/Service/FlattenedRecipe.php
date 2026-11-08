<?php

namespace Repas\Repas\Application\Service;

use Repas\Repas\Domain\Model\FlattenedRows;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Repas\Domain\Service\RecipeFlattener;
use Repas\Shared\Domain\Tool\Tab;

/**
 * Une recette et ses recettes utilisées, déjà lues (`FlattenedRecipeRows::of`), prête à être mise à l'échelle autant de fois qu'il le faut : une
 * fois par repas d'un groupe qui change de nombre de personnes ou qui est retiré (ADR 0036 décision 9). Ne lit plus rien.
 */
final readonly class FlattenedRecipe
{
    /**
     * @param array<string, Recipe> $components les recettes utilisées lisibles du lecteur, par identifiant (vide sans composant)
     */
    public function __construct(
        private RecipeFlattener $flattener,
        private Recipe $recipe,
        private array $components,
    ) {
    }

    /**
     * Les lignes pour ce nombre de personnes (des copies : appelable plusieurs fois, la recette garde ses quantités).
     */
    public function forServing(int $serving): FlattenedRows
    {
        return $this->flattener->flatten($this->recipe, $this->components, $serving);
    }

    /**
     * Toutes les lignes, au nombre de personnes de la recette : de quoi préparer en une fois les conversions et les parents de tous les produits
     * (`ConversionService::preload`, `PartAwareLines::parentsOf`), quel que soit le nombre de personnes demandé ensuite.
     *
     * @return Tab<RecipeRow>
     */
    public function allRows(): Tab
    {
        return $this->forServing($this->recipe->getServing())->rows();
    }
}
