<?php

namespace Repas\Repas\Application\Command\ClassifyBaseIngredients;


/**
 * Ce que la phase 1 a fait. Des nombres seulement.
 */
final readonly class ClassificationResult
{
    /**
     * @param int $classified le nombre d'ingrédients sans créateur (0 : il n'en reste aucun, la passation est faite : rien à classer)
     * @param int $base dont « de base » d'après le fichier
     * @param int $specific dont « spécifiques »
     * @param int $changed les marqueurs réellement écrits (0 : le classement était déjà posé, un rejeu n'écrit rien)
     */
    public function __construct(
        public int $classified,
        public int $base,
        public int $specific,
        public int $changed,
    ) {
    }
}
