<?php

namespace Repas\Repas\Application\Loader;

use Repas\Repas\Application\Query\ListRecipeTypes\RecipeTypeCountDTO;
use Repas\Repas\Application\Query\ListRecipeTypes\RecipeTypeDTO;
use Repas\Repas\Domain\Exception\RecipeException;

interface RecipeTypeLoader
{
    /**
     * Tous les types de recette, triés par ordre d'affichage croissant (puis par slug), chacun avec le nombre
     * de recettes de l'utilisateur (auteur, copies comprises : le filtre de `RecipeLoader::listByAuthorAndType`).
     *
     * @return list<RecipeTypeCountDTO>
     */
    public function list(string $userId): array;

    /**
     * @throws RecipeException type inconnu
     */
    public function get(string $slug): RecipeTypeDTO;
}
