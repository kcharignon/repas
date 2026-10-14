<?php

namespace Repas\Repas\Application\Loader;

use Repas\Repas\Application\Query\GetIngredient\IngredientDTO;
use Repas\Repas\Application\Query\ListAvailableIngredients\IngredientOptionDTO;
use Repas\Repas\Application\Query\ListCompatibleUnits\CompatibleUnitDTO;
use Repas\Repas\Application\Query\ListIngredientsByDepartment\DepartmentIngredientsDTO;
use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Exception\IngredientException;

interface IngredientLoader
{
    /**
     * Rayon et ingrédients communs ou créés par l'utilisateur dans ce rayon, triés par nom. `canEdit` : l'ingrédient est
     * à l'utilisateur, ou commun et l'utilisateur est administrateur.
     *
     * @throws DepartmentException
     */
    public function listByDepartment(string $departmentSlug, string $viewerId, bool $viewerIsAdmin): DepartmentIngredientsDTO;

    /**
     * Un ingrédient créé par un autre utilisateur n'existe pas pour qui n'est pas administrateur.
     *
     * @throws IngredientException
     */
    public function get(string $slug, string $viewerId, bool $viewerIsAdmin): IngredientDTO;

    /**
     * Unités compatibles d'un ingrédient, dans l'ordre où elles sont enregistrées.
     *
     * @return list<CompatibleUnitDTO>
     * @throws IngredientException
     */
    public function listCompatibleUnits(string $ingredientSlug): array;
    /**
     * Ingrédients que l'utilisateur peut utiliser (communs et les siens), triés par nom : choix des formulaires et des recettes.
     * `$search` ne garde que ceux dont le nom contient ce texte, sans tenir compte de la casse, des accents ni des espaces.
     *
     * @return list<IngredientOptionDTO>
     */
    public function listAvailable(string $userId, ?string $search = null): array;
}
