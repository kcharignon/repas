<?php

namespace Repas\Repas\Application\Command\ImportMarmitonRecipe;


/**
 * Le résultat d'un import (rendu par le handler, ADR 0015 §12) : la recette de l'utilisateur, et si elle vient d'être créée (`201`) ou s'il l'avait
 * déjà (`200`, rien n'a été écrit).
 */
final readonly class ImportedMarmitonRecipe
{
    public function __construct(
        public string $recipeId,
        public bool $created,
    ) {
    }
}
