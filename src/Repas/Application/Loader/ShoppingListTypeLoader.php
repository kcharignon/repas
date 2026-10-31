<?php

namespace Repas\Repas\Application\Loader;

use Repas\Repas\Application\Query\GetShoppingListType\ShoppingListTypeDTO;
use Repas\Repas\Application\Query\ListShoppingListTypes\ShoppingListTypeSummaryDTO;
use Repas\Repas\Domain\Exception\ShoppingListTypeException;

/**
 * Port de lecture des types de liste de courses d'un utilisateur (ADR 0036). Un nombre constant de requêtes, quel que soit le nombre de types,
 * de rayons et de produits ; jamais le type d'un autre utilisateur, un administrateur compris.
 */
interface ShoppingListTypeLoader
{
    /**
     * Les types de l'utilisateur, triés par date de création puis par identifiant, chacun avec ses rayons et le nombre de ses produits à part.
     *
     * @return list<ShoppingListTypeSummaryDTO>
     */
    public function list(string $userId): array;

    /**
     * Un type de l'utilisateur, avec ses rayons (nom compris) et ses produits à part (nom et rayon).
     *
     * @throws ShoppingListTypeException le type est inconnu, ou il est à un autre utilisateur (le même refus)
     */
    public function get(string $id, string $userId): ShoppingListTypeDTO;
}
