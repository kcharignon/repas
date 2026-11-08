<?php

namespace Repas\Repas\Application\Query\ListUsableRecipes;

use Repas\Shared\Application\Interface\QueryInterface;

final readonly class ListUsableRecipesQuery implements QueryInterface
{
    /** Recettes rendues : celles qu'un éditeur propose en une liste déroulante, jamais une page à tourner (`totalItems` dit le vrai nombre). */
    public const int MAX_ITEMS = 200;

    /**
     * @param string|null $forRecipeId la recette qu'on écrit ou qu'on modifie (ADR 0040 décision 22) : les recettes proposées sont celles de SON auteur,
     *                                 jamais elle-même ; `null` : une recette qu'on est en train de créer, donc celles de l'utilisateur. Le droit
     *                                 `RECIPE_OWNER` sur cette recette est jugé à la frontière HTTP
     * @param bool $secret la recette écrite est (ou va devenir) secrète : ses recettes secrètes sont alors proposées aussi, à son auteur seul ;
     *                     faux (le défaut) : seulement des recettes ordinaires
     * @param string|null $search le texte cherché dans le nom des recettes, 100 caractères au plus à la frontière HTTP ; `null` ou vide : toutes,
     *                            triées par nom
     */
    public function __construct(
        public string $userId,
        public ?string $forRecipeId = null,
        public bool $secret = false,
        public ?string $search = null,
    ) {
    }
}
