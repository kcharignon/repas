<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Exception\ShoppingListTypeException;
use Repas\Repas\Domain\Model\ShoppingListType;

/**
 * Les types de liste de courses d'un utilisateur et leurs rayons (ADR 0036, LST-002). Le type et ses rattachements de rayons
 * (`department_list_type`, un rayon dans un seul type par utilisateur) s'écrivent ensemble : ils sont un seul agrégat. La LECTURE pour
 * l'écran est faite par `ShoppingListTypeLoader`.
 */
interface ShoppingListTypeRepository
{
    /**
     * Le type avec ses rayons. Un type d'un autre utilisateur se charge aussi : c'est au cas d'usage de juger le propriétaire.
     *
     * @throws ShoppingListTypeException
     */
    public function findOneById(string $id): ShoppingListType;

    /**
     * Le propriétaire a-t-il au moins `$count` types ? La lecture s'arrête au `$count`-ième : jamais un décompte complet (plafond de types,
     * `ShoppingListType::MAX_PER_OWNER`).
     */
    public function ownerHasAtLeast(string $ownerId, int $count): bool;

    /**
     * Le propriétaire a-t-il déjà un type de ce nom normalisé (hors le type `$exceptId`, qui peut garder son propre nom) ?
     */
    public function ownerHasTypeNamed(string $ownerId, string $sluggedName, ?string $exceptId = null): bool;

    /**
     * Enregistre le type (créé ou modifié) et le jeu COMPLET de ses rayons : ceux qu'il n'a plus sont détachés, et un rayon rattaché alors qu'un
     * AUTRE type du même propriétaire l'avait est déplacé (un rayon est dans un seul type à la fois).
     */
    public function save(ShoppingListType $listType): void;

    /**
     * Supprime le type et ses rattachements de rayons. Les produits, les listes qui le désignent sont remis en ordre avant par le cas d'usage
     * (`IngredientRepository::clearListDestination`, `ShoppingListRepository::detachFromListType`).
     */
    public function delete(ShoppingListType $listType): void;

    /**
     * Efface tous les types et rattachements d'un compte supprimé (ADR 0021). Opération en masse.
     */
    public function deleteByOwner(string $ownerId): void;
}
