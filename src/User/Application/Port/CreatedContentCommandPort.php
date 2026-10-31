<?php

namespace Repas\User\Application\Port;

/**
 * Ce qu'un compte a créé dans le contexte des recettes, pour recompter ses statistiques. Adapté depuis `Repas` (ADR 0035).
 */
interface CreatedContentCommandPort
{
    /** Les ingrédients que le compte a créés lui-même (une copie reçue ou faite avec une recette n'en est pas un). */
    public function createdIngredients(string $userId): int;

    /** Les recettes du compte. */
    public function recipes(string $userId): int;
}
