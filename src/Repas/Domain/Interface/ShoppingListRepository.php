<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Shared\Domain\Tool\Tab;

interface ShoppingListRepository
{
    /**
     * @return Tab<ShoppingList>
     */
    public function findByOwner(string $ownerId): Tab;

    public function findOneById(string $id): ShoppingList;

    /**
     * La liste en cours de la liste PRINCIPALE du propriétaire (celle sans type, ADR 0036) : une liste en cours d'un type n'est jamais
     * rendue ici, un compte sans type voit exactement ce qu'il voyait avant.
     */
    public function findOneActivateByOwner(string $ownerId): ?ShoppingList;

    /**
     * La liste en cours (`ACTIVE`) de ce type du propriétaire, `null` pour la liste principale (ADR 0036 décision 6 : une seule par type et
     * une pour la principale). Si deux existent (deux ouvertures simultanées, ADR 0036 « Conséquences »), la plus ancienne.
     */
    public function findOneActiveByOwnerAndType(string $ownerId, ?string $listTypeId): ?ShoppingList;

    /**
     * L'utilisateur a-t-il une liste en cours de ce type (la liste principale par défaut) ? Sans hydrater l'agrégat ni importer le domaine du
     * contexte User.
     */
    public function hasActiveShoppingList(string $ownerId, ?string $listTypeId = null): bool;

    /**
     * La liste est-elle envoyée au drive (`SENT`, ADR 0023) ? Une requête, sans hydrater l'agrégat : cocher et décocher ne
     * chargent qu'une ligne. Une liste inconnue n'est pas envoyée.
     */
    public function isSent(string $shoppingListId): bool;

    /**
     * L'utilisateur a-t-il une liste envoyée au drive (`SENT`) ? Une requête, sans hydrater l'agrégat (commande vocale : dire que la
     * nouvelle liste remplace une liste envoyée, ADR 0029 décision 5).
     */
    public function hasSentShoppingList(string $ownerId): bool;

    public function save(ShoppingList $shoppingList): void;

    /**
     * Le propriétaire a-t-il au moins `$count` listes à garder (hors listes terminées, supprimées à la création de la suivante) ? La lecture
     * s'arrête à la `$count`-ième : jamais un décompte complet (plafond de listes, `ShoppingList::MAX_PER_OWNER`).
     */
    public function ownerHasAtLeast(string $ownerId, int $count): bool;

    public function delete(ShoppingList $shoppingList): void;

    /**
     * Identifiants seulement : de quoi agir sur ces listes sans les hydrater.
     *
     * @return list<string>
     */
    public function findIdsByOwnerAndStatus(string $ownerId, ShoppingListStatus $status): array;

    public function findOneByMealId(string $mealId): ShoppingList;

    /**
     * Une liste de courses contient-elle cet ingrédient ? (existence seulement : aucun agrégat n'est hydraté)
     */
    public function isIngredientUsed(string $ingredientSlug): bool;

    /**
     * Un repas d'une liste de courses est-il planifié avec cette recette ?
     */
    public function isRecipePlanned(string $recipeId): bool;

    /**
     * Supprime toutes les listes d'un propriétaire, avec leurs repas, ingrédients et lignes (suppression d'un compte,
     * ADR 0021). Opération en masse : les agrégats déjà chargés ne sont pas mis à jour.
     */
    public function deleteByOwner(string $ownerId): void;

    /**
     * Retire des listes de courses, quel qu'en soit le propriétaire, les repas planifiés avec ces recettes. Les quantités
     * des lignes ne sont pas recalculées (suppression d'un compte, ADR 0021).
     *
     * @param list<string> $recipeIds
     */
    public function removeMealsOfRecipes(array $recipeIds): void;

    /**
     * Suppression d'un type de liste (ADR 0036 décision 15) : toutes les listes de ce propriétaire qui portent ce type n'en ont plus (elles
     * deviennent des listes sans type, sous leur nom), et celle qui est en cours passe en pause, version +1. Une liste envoyée au drive ou
     * terminée garde son statut ; aucune ligne, aucun repas n'est déplacé ni fusionné. Une seule instruction, aucun agrégat hydraté (c'est la
     * règle de `ShoppingList::detachFromListType`, en masse).
     */
    public function detachFromListType(string $ownerId, string $listTypeId): void;
}
