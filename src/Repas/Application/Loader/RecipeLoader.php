<?php

namespace Repas\Repas\Application\Loader;

use Repas\Repas\Application\Query\GetRecipe\RecipeDTO;
use Repas\Repas\Application\Query\ListOtherPeopleRecipes\OtherPeopleRecipesDTO;
use Repas\Repas\Application\Query\ListRecipesByType\RecipeTypeRecipesDTO;
use Repas\Repas\Domain\Exception\RecipeException;

interface RecipeLoader
{
    /**
     * Recette avec ses ingrédients, dans l'ordre de la recette (colonne `position`).
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
     * Une page des recettes originales des autres utilisateurs que cet utilisateur n'a pas encore copiées. `totalItems` est le nombre de
     * toutes ces recettes (ou de toutes les correspondances), quelle que soit la page.
     *
     * Sans recherche : triées par catégorie (l'ordre des types de recette), puis par nom, puis par identifiant. Avec une recherche : celles dont
     * le nom correspond au texte, du plus pertinent au moins pertinent (`SearchRelevance`, ADR 0019), à pertinence égale par nom puis par
     * identifiant, toutes catégories confondues ; un texte sans lettre ni chiffre ne trouve rien.
     */
    public function listOfOtherPeople(string $userId, int $limit, int $offset, ?string $search = null): OtherPeopleRecipesDTO;
}
