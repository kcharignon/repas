<?php

namespace Repas\Repas\Application\Loader;

use Repas\Repas\Application\Query\ListRecipeTypes\RecipeTypeDTO;
use Repas\Repas\Domain\Exception\RecipeException;

interface RecipeTypeLoader
{
    /**
     * Tous les types de recette, triés par ordre d'affichage croissant (puis par slug).
     *
     * @return list<RecipeTypeDTO>
     */
    public function list(): array;

    /**
     * @throws RecipeException type inconnu
     */
    public function get(string $slug): RecipeTypeDTO;
}
