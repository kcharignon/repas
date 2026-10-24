<?php

namespace Repas\Repas\Application\Command\SeedUserIngredients;


/**
 * Amorçage des ingrédients d'UN compte (ADR 0028, amendement du 2026-10-20, ING-008, `docs/ingredients/sans-commun.md` §§ 2 et 6) : lui
 * copier les ingrédients « de base » du propriétaire du catalogue, avec leurs conversions propres et un alias d'adoption par copie, et
 * repointer vers ces copies tout ce qui, chez lui, référençait un de ces ingrédients. Une transaction, donc un compte amorcé en entier ou
 * pas du tout. Lancée par l'écouteur de l'activation d'un compte (`source = activation`, interrupteur allumé) et par la commande de
 * console `repas:ingredients:seed` (le rattrapage), jamais par une route.
 */
final readonly class SeedUserIngredientsCommand
{
    /** La source écrite dans les alias quand l'amorçage suit l'activation d'un compte. */
    public const string SOURCE_ACTIVATION = 'activation';

    /**
     * @param string $source le nom du lancement, écrit dans chaque alias (`activation`, ou `rattrapage-AAAA-MM-JJ` ; 64 caractères au plus)
     * @param bool $acceptSent autorise le repointage des listes déjà envoyées au drive (immuables pour l'API, ADR 0023) ; un compte activé
     *        n'en a pas, un compte rattrapé peut en avoir
     * @param bool $complete complète aussi un compte DÉJÀ amorcé (console seulement, sur demande explicite pour un compte : rien n'est
     *        jamais propagé automatiquement, ADR 0028 décision 6) : il reçoit ce que le catalogue a de plus, et ses lignes restées sur
     *        l'origine d'une adoption qu'il a déjà (écriture concurrente d'un vieil onglet) sont repointées sur sa copie
     */
    public function __construct(
        public string $userId,
        public string $source,
        public bool $acceptSent = true,
        public bool $complete = false,
    ) {
    }
}
