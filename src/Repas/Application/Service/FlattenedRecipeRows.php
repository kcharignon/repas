<?php

namespace Repas\Repas\Application\Service;

use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Service\RecipeFlattener;

/**
 * Lit les recettes utilisées d'une recette pour l'aplatir (ADR 0040 décision 12) : le service d'application que `PlannedMealHandler`,
 * `UpdateServingMealHandler` et `removeMealFromPlanHandler` appellent à la place de `Recipe::getRowForServing`.
 *
 * Coût : une recette SANS composant ne lit rien (`Recipe::hasComponents()` se sait avec la recette elle-même : zéro requête de plus, les budgets
 * d'aujourd'hui) ; avec, ses recettes utilisées se lisent EN LOT (`RecipeRepository::findByIds`), quel que soit leur nombre. Une recette utilisée
 * qui n'existe plus, ou que le lecteur ne peut pas voir (la secrète d'un autre), est ignorée, jamais une erreur (décision 21).
 */
readonly class FlattenedRecipeRows
{
    public function __construct(
        private RecipeRepository $recipeRepository,
        private RecipeFlattener $flattener,
    ) {
    }

    /**
     * @param string $viewerId le propriétaire de la liste de courses : une recette utilisée qu'il ne voit pas n'apporte rien à sa liste
     *
     * @throws RecipeException la recette dit avoir des composants mais ses arêtes n'ont pas été lues, et elle n'existe plus
     */
    public function of(Recipe $recipe, string $viewerId): FlattenedRecipe
    {
        if (!$recipe->hasComponents()) {
            return new FlattenedRecipe($this->flattener, $recipe, []);
        }

        // Un modèle chargé sans ses arêtes (`findByIds`) sait pourtant qu'il en a : planifier sans elles omettrait des ingrédients sans aucune erreur
        if ($recipe->getComponents()->empty()) {
            $recipe = $this->recipeRepository->findOneById($recipe->getId());
        }

        $usable = array_filter(
            $this->recipeRepository->findByIds($recipe->componentIds()),
            static fn(Recipe $used): bool => $used->isVisibleTo($viewerId),
        );

        return new FlattenedRecipe($this->flattener, $recipe, $usable);
    }
}
