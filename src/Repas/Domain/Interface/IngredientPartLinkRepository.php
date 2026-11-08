<?php

namespace Repas\Repas\Domain\Interface;


/**
 * Le port de la LIAISON des sous-ingrédients d'un compte (ADR 0038 décisions 5 et 18 à 24, SUB-002) : une copie d'ingrédient ne recopie jamais
 * `parent_slug` (le slug du parent d'un original est celui d'un ingrédient d'un autre compte), le découpage se REFAIT chez chaque compte par
 * provenance (`adopted_from`) après chaque copie : l'amorçage à l'activation, le rattrapage `--complete`, la copie d'une recette.
 *
 * Une seule instruction, rejouable, en SQL direct (aucun agrégat hydraté, aucun événement) : ce n'est pas une écriture de l'utilisateur.
 */
interface IngredientPartLinkRepository
{
    /**
     * Relie les copies du compte. Pour chaque ingrédient `c` du compte SANS parent dont l'origine `o` (`o.slug = c.adopted_from`) a un parent
     * `po`, si le compte a un ingrédient `pc` dont l'origine est celle de `po` (`pc.adopted_from = COALESCE(po.adopted_from, po.slug)`), alors
     * `c` devient une partie de `pc`, avec le ratio de `o`. Elle ne fait que cela et jamais :
     *
     * - d'écrasement : un ingrédient qui a déjà un parent (celui que l'utilisateur a posé, ou un lien déjà fait) n'est pas touché ;
     * - de chaîne : `pc` n'est pas une partie (ni ne peut en devenir une), `c` n'a pas de partie à lui ;
     * - de dépassement : un parent garde au plus `Ingredient::MAX_PARTS` parties, celles que la même instruction pose comprises ;
     * - de partie qui a une ligne dans une liste non terminée (comme le refuse `PUT /ingredients/{slug}/parent`, `409 INGREDIENT_PART_IN_LIST`) :
     *   elle reste ordinaire et sera reliée par un prochain appel, quand la liste est terminée ;
     * - de lien vers un ingrédient d'un autre compte, ni d'écriture sur l'ingrédient d'un autre compte.
     *
     * Rejouable : un second appel ne trouve rien à écrire et rend 0. Le propriétaire réservé des recettes Marmiton n'est jamais relié (ses
     * ingrédients n'ont jamais de parent, ADR 0037).
     *
     * À appeler dans la transaction du bus de commandes, APRÈS les copies (et leur repointage) : un échec annule la copie ou l'amorçage (ADR 0038
     * décision 24).
     *
     * @phpstan-impure
     *
     * @return int le nombre de liens posés
     */
    public function linkCopies(string $userId): int;
}
