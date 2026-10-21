<?php

namespace Repas\Repas\Application\Loader;

use Repas\Repas\Application\Query\GetIngredient\IngredientDTO;
use Repas\Repas\Application\Query\ListAvailableIngredients\IngredientOptionDTO;
use Repas\Repas\Application\Query\ListCompatibleUnits\CompatibleUnitDTO;
use Repas\Repas\Application\Query\ListIngredientsByDepartment\DepartmentIngredientsDTO;
use Repas\Repas\Application\Query\SearchIngredients\IngredientSearchResultDTO;
use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Exception\IngredientException;

interface IngredientLoader
{
    /**
     * Rayon et ingrédients de l'utilisateur dans ce rayon, triés par nom : seulement les siens, administrateur compris (ADR 0028,
     * amendement). `canEdit` est toujours vrai, le champ reste dans le contrat.
     *
     * @throws DepartmentException
     */
    public function listByDepartment(string $departmentSlug, string $viewerId): DepartmentIngredientsDTO;

    /**
     * Un ingrédient créé par un autre utilisateur n'existe pas pour le lecteur, administrateur compris.
     *
     * @throws IngredientException
     */
    public function get(string $slug, string $viewerId): IngredientDTO;

    /**
     * Unités compatibles d'un ingrédient, dans l'ordre où elles sont enregistrées.
     *
     * @return list<CompatibleUnitDTO>
     * @throws IngredientException
     */
    public function listCompatibleUnits(string $ingredientSlug): array;
    /**
     * Ingrédients que l'utilisateur peut utiliser (les siens), triés par nom : sélecteur d'ingrédients des recettes.
     * `$search` ne garde que ceux dont le nom contient ce texte, sans tenir compte de la casse, des accents ni des espaces.
     *
     * @return list<IngredientOptionDTO>
     */
    public function listAvailable(string $userId, ?string $search = null): array;

    /**
     * Ingrédients de l'utilisateur dont le nom ressemble à `$search` (sans tenir compte de la casse ni des accents, fautes de frappe
     * tolérées), du plus pertinent au moins pertinent (`SearchRelevance`), avec leur rayon. Une recherche sans lettre ni chiffre ne
     * trouve rien.
     *
     * @return list<IngredientSearchResultDTO>
     */
    public function search(string $search, string $viewerId): array;
}
