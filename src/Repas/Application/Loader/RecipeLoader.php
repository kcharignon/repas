<?php

namespace Repas\Repas\Application\Loader;

use Repas\Repas\Application\Query\GetRecipe\RecipeDTO;
use Repas\Repas\Application\Query\ListOtherPeopleRecipes\OtherPeopleRecipesDTO;
use Repas\Repas\Application\Query\ListRecipesByType\RecipeSummaryDTO;
use Repas\Repas\Application\Query\ListRecipesByType\RecipeTypeRecipesDTO;
use Repas\Repas\Domain\Exception\RecipeException;

interface RecipeLoader
{
    /**
     * Recette avec ses ingrédients, dans l'ordre de la recette (colonne `position`), telle que cet utilisateur la lit. Une recette secrète
     * n'est lue que par son auteur (ADR 0033) : pour tout autre, administrateur compris, elle est introuvable, comme une recette inconnue.
     *
     * @throws RecipeException la recette est inconnue, ou secrète et d'un autre
     */
    public function get(string $id, string $userId): RecipeDTO;

    /**
     * Type de recette et recettes de l'auteur pour ce type, triées par slug, secrètes comprises (chacune dit si elle l'est).
     *
     * @throws RecipeException type inconnu
     */
    public function listByAuthorAndType(string $authorId, string $typeSlug): RecipeTypeRecipesDTO;

    /**
     * Les recettes que cet auteur a mises en favoris, tous types confondus, secrètes comprises (chacune dit si elle l'est), triées par slug
     * puis par identifiant. Vide sans favori.
     *
     * @return list<RecipeSummaryDTO>
     */
    public function listFavoritesOfAuthor(string $authorId): array;

    /**
     * Une page des recettes originales des autres utilisateurs que cet utilisateur n'a pas encore copiées, SANS les recettes secrètes (ADR 0033 :
     * ni dans la page, ni dans le total, ni dans la recherche). `totalItems` est le nombre de toutes ces recettes (ou de toutes les
     * correspondances), quelle que soit la page.
     *
     * Sans recherche : triées par catégorie (l'ordre des types de recette), puis par nom, puis par identifiant. Avec une recherche : celles dont
     * le nom correspond au texte, du plus pertinent au moins pertinent (`SearchRelevance`, ADR 0019), à pertinence égale par nom puis par
     * identifiant, toutes catégories confondues ; un texte sans lettre ni chiffre ne trouve rien.
     */
    public function listOfOtherPeople(string $userId, int $limit, int $offset, ?string $search = null): OtherPeopleRecipesDTO;
}
