<?php

namespace Repas\Repas\Application\Query\ListUsableRecipes;

use Repas\Repas\Application\Loader\RecipeLoader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListUsableRecipesQueryHandler
{
    public function __construct(
        private RecipeLoader $recipeLoader,
    ) {
    }

    public function __invoke(ListUsableRecipesQuery $query): UsableRecipesDTO
    {
        return $this->recipeLoader->listUsable(
            $query->userId,
            // Une recette vide n'est pas une recette : on propose celles de l'utilisateur
            $query->forRecipeId !== null && trim($query->forRecipeId) !== '' ? $query->forRecipeId : null,
            $query->secret,
            // Un texte réduit à des espaces n'est pas une recherche : toutes les recettes, triées par nom
            $query->search !== null && trim($query->search) !== '' ? $query->search : null,
        );
    }
}
