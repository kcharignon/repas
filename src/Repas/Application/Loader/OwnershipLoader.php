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
     * Identifiant du propriétaire d'un ingrédient (son créateur). Il n'y a plus d'ingrédient commun ; le type reste nullable tant que
     * la colonne l'est (ING-014).
     *
     * @throws DomainException
     */
    public function ingredientCreatorId(string $slug): ?string;

    /**
     * @throws DomainException
     */
    public function shoppingListOwnerId(string $shoppingListId): string;

    /**
     * Identifiant de l'auteur d'une recette, telle que cet utilisateur la voit. Une recette secrète (ADR 0033) n'existe que pour son auteur :
     * pour tout autre, administrateur compris, elle est introuvable, comme une recette inconnue (`404`, la même réponse : jamais un `403`,
     * qui révélerait qu'elle existe).
     *
     * @throws DomainException la recette est inconnue, ou secrète et d'un autre
     */
    public function recipeAuthorIdFor(string $recipeId, string $userId): string;

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
