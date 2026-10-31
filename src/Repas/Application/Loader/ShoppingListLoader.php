<?php

namespace Repas\Repas\Application\Loader;

use Repas\Repas\Application\Query\GetActiveShoppingListSummary\ActiveShoppingListSummaryDTO;
use Repas\Repas\Application\Query\GetShoppingList\ShoppingListDetailDTO;
use Repas\Repas\Application\Query\ListOpenShoppingLists\OpenShoppingListDTO;
use Repas\Repas\Application\Query\ListShoppingLists\ShoppingListDTO;
use Repas\Repas\Domain\Exception\MealException;
use Repas\Repas\Domain\Exception\ShoppingListException;

interface ShoppingListLoader
{
    /**
     * Listes de courses d'un utilisateur, de la plus récente à la plus ancienne.
     *
     * @return list<ShoppingListDTO>
     */
    public function listByOwner(string $ownerId): array;

    /**
     * Listes EN COURS d'un utilisateur (ADR 0036 décision 8) : la liste principale d'abord, puis une par type par date de création du type puis
     * identifiant. Une seule lecture, quel que soit le nombre de types.
     *
     * @return list<OpenShoppingListDTO>
     */
    public function listOpenByOwner(string $ownerId): array;

    /**
     * Ce que contient la liste active PRINCIPALE d'un utilisateur (celle sans type, ADR 0036) : quantité par ingrédient et recettes planifiées.
     * Sans liste active, un résumé vide (identifiant null) : ce n'est pas une erreur.
     */
    public function getActiveSummary(string $ownerId): ActiveShoppingListSummaryDTO;

    /**
     * Détail d'une liste : ingrédients groupés par rayon, recettes groupées par type.
     *
     * @throws ShoppingListException
     */
    public function get(string $id): ShoppingListDetailDTO;

    /**
     * Liste « en cours » d'un utilisateur : l'active, à défaut la plus récente, null s'il n'en a aucune.
     */
    public function getCurrent(string $ownerId): ?ShoppingListDetailDTO;

    /**
     * Détail de la liste qui contient ce repas.
     *
     * @throws MealException
     */
    public function getByMealId(string $mealId): ShoppingListDetailDTO;
}
