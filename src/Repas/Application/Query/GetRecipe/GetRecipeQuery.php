<?php

namespace Repas\Repas\Application\Query\GetRecipe;

use Repas\Shared\Application\Interface\QueryInterface;

final readonly class GetRecipeQuery implements QueryInterface
{
    /**
     * @param string $userId l'utilisateur qui lit : une recette secrète n'est lue que par son auteur (ADR 0033), pour tout autre elle n'existe pas
     * @param bool $isAdministrator l'utilisateur est administrateur : il modifie toute recette qu'il lit (`canEdit`)
     */
    public function __construct(
        public string $recipeId,
        public string $userId,
        public bool $isAdministrator = false,
    ) {
    }
}
