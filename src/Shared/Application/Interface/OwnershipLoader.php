<?php

namespace Repas\Shared\Application\Interface;

use Repas\Shared\Domain\Exception\DomainException;

/**
 * Port de lecture de l'appartenance des ressources, utilisé par le voter des droits (`OwnerVoter`) : il ne renvoie que
 * des identifiants d'utilisateur, jamais un agrégat. Une ressource introuvable lève l'exception de domaine
 * `notFound` de son agrégat (code 404).
 */
interface OwnershipLoader
{
    /**
     * Identifiant du créateur d'un ingrédient, null pour un ingrédient commun.
     *
     * @throws DomainException
     */
    public function ingredientCreatorId(string $slug): ?string;

    /**
     * @throws DomainException
     */
    public function shoppingListOwnerId(string $shoppingListId): string;

    /**
     * @throws DomainException
     */
    public function recipeAuthorId(string $recipeId): string;

    /**
     * Identifiant du propriétaire de la liste de courses qui contient ce repas.
     *
     * @throws DomainException
     */
    public function mealOwnerId(string $mealId): string;

    /**
     * Identifiant du propriétaire de la liste de courses qui contient cette ligne.
     *
     * @throws DomainException
     */
    public function shoppingListRowOwnerId(string $shoppingListRowId): string;
}
