<?php

namespace Repas\Repas\Domain\Service;


/**
 * LA règle qui dit dans quelle liste de courses va un produit (ADR 0036 décision 3), écrite une seule fois comme
 * `IngredientVisibility::visibleBy` l'est pour la visibilité. Pure : des identifiants, aucune lecture.
 *
 * Dans l'ordre :
 *  1. le choix fait sur le produit (`ingredient.list_destination`) : un type de liste de l'utilisateur, ou la valeur réservée `main`
 *     (la liste principale, même si le rayon du produit est dans un type) ;
 *  2. sinon le type de son rayon (`department_list_type`) ;
 *  3. sinon la liste principale.
 *
 * Le résultat est `null` pour la liste principale, sinon l'identifiant du type. Un type qui n'existe plus (ou qui n'est pas à
 * l'utilisateur) ne compte pas : la règle suivante prend la main, jamais une erreur. Le port `ListRoutingRepository` la tient à jour
 * des types qui existent et la donne pour N produits en une lecture.
 */
final readonly class ListDestinationResolver
{
    /** La valeur réservée de `ingredient.list_destination` : « la liste principale », choisie à la main. */
    public const string MAIN = 'main';

    /**
     * @param string|null  $choice          `ingredient.list_destination` : `null` (pas de choix), `main`, ou un identifiant de type
     * @param string|null  $departmentTypeId le type auquel l'utilisateur a rattaché le rayon du produit, s'il en a un
     * @param list<string> $existingTypeIds  les types de l'utilisateur qui existent, de quoi écarter un identifiant périmé
     *
     * @return string|null `null` = la liste principale, sinon l'identifiant du type de liste
     */
    public function resolve(?string $choice, ?string $departmentTypeId, array $existingTypeIds): ?string
    {
        if ($choice === self::MAIN) {
            return null;
        }
        if ($choice !== null && in_array($choice, $existingTypeIds, true)) {
            return $choice;
        }
        if ($departmentTypeId !== null && in_array($departmentTypeId, $existingTypeIds, true)) {
            return $departmentTypeId;
        }

        return null;
    }
}
