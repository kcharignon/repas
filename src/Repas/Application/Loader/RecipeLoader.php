<?php

namespace Repas\Repas\Application\Loader;

use Repas\Repas\Application\Query\GetRecipe\RecipeDTO;
use Repas\Repas\Application\Query\ListOtherPeopleRecipes\OtherPeopleRecipeDTO;
use Repas\Repas\Application\Query\ListRecipesByType\RecipeTypeRecipesDTO;
use Repas\Repas\Domain\Exception\RecipeException;

interface RecipeLoader
{
    /**
     * Recette avec ses ingrédients, triés par nom.
     *
     * @throws RecipeException
     */
    public function get(string $id): RecipeDTO;

    /**
     * Type de recette et recettes de l'auteur pour ce type, triées par slug.
     *
     * @throws RecipeException type inconnu
     */
    public function listByAuthorAndType(string $authorId, string $typeSlug): RecipeTypeRecipesDTO;

    /**
     * Recettes originales des autres utilisateurs que cet utilisateur n'a pas encore copiées, triées par slug.
     *
     * @return list<OtherPeopleRecipeDTO>
     */
    public function listOfOtherPeople(string $userId): array;
}
