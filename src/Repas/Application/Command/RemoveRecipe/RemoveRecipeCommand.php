<?php

namespace Repas\Repas\Application\Command\RemoveRecipe;


readonly class RemoveRecipeCommand
{

    /**
     * @param bool $byAdministrator l'utilisateur est administrateur (jugé par le contrôleur) : lui seul supprime une recette SANS PROPRIÉTAIRE, la
     *                              neutre d'une recette Marmiton (ADR 0037, amendement du 2026-11-08)
     */
    public function __construct(
        public string $recipeId,
        public ?int $expectedVersion = null,
        public bool $byAdministrator = false,
    ) {
    }
}
