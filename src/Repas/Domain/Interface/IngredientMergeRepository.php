<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Model\IngredientMergeRepointed;
use Repas\Repas\Domain\Model\IngredientMergeUsage;
use Repas\Repas\Domain\Model\UnitFactor;

/**
 * Les lectures et les écritures EN MASSE de la fusion de deux ingrédients (ADR 0028 décision 9, `docs/ingredients/fusion.md` § 3) :
 * un nombre de requêtes constant, quels que soient le nombre de recettes et de listes, sans hydrater un agrégat. `B` est l'ingrédient
 * absorbé, `A` l'ingrédient gardé. Le contexte `Drive` repointe lui-même ses choix (port `Shared` `DriveIngredientReferences`).
 *
 * Un port d'écriture (lu par un `CommandHandler`) : il ne s'appelle donc pas `…Loader` (Deptrac `no-loader-in-commandhandler`).
 * Toutes les écritures s'exécutent dans la transaction du bus de commandes : la fusion se fait en entier ou pas du tout.
 */
interface IngredientMergeRepository
{
    /**
     * Verrouille les deux ingrédients (`SELECT … FOR UPDATE`, dans l'ordre alphabétique des slugs) : deux fusions concurrentes qui les
     * touchent se mettent à la queue au lieu de se croiser. À appeler en premier.
     */
    public function lock(string $firstSlug, string $secondSlug): void;

    /**
     * Ce que la fusion de `$absorbedSlug` dans `$keptSlug` touchera chez cet utilisateur (recettes, listes, unités, listes envoyées),
     * combien de recettes et de listes utiliseront `A` APRÈS la fusion (l'union, sans doublon), les conversions propres de `B`, et si une
     * ligne d'un autre utilisateur référence l'ingrédient absorbé. Lecture seule, en UNE requête.
     *
     * @param string $keptPurchaseUnitSlug l'unité d'achat de `A` : l'unité cible des quantités de liste quand `A` n'a pas encore de ligne
     */
    public function usageOf(string $userId, string $absorbedSlug, string $keptSlug, string $keptPurchaseUnitSlug): IngredientMergeUsage;

    /**
     * L'étape 0b de `fusion.md` § 3 : copie sur `A` des conversions propres de `B` que `IngredientMergePlanner` a décidé de récupérer, EN UNE
     * requête (unités et coefficient recopiés tels quels depuis la conversion d'origine, un nouvel identifiant chacune). Celles de `B`
     * restent en place : elles disparaissent avec lui, plus tard.
     *
     * @param array<string, string> $newIdBySourceId l'identifiant de chaque conversion de `B` à copier -> l'identifiant de sa copie
     *
     * @throws IngredientException `INGREDIENT_MERGE_CONFLICT` si une conversion à copier n'existe plus chez `B` (écriture concurrente)
     */
    public function recoverConversions(string $absorbedSlug, string $keptSlug, array $newIdBySourceId): void;

    /**
     * Les étapes 1 à 3 de `fusion.md` § 3, ensemble (le calcul d'une liste relit le slug courant des lignes de la recette : l'un sans
     * l'autre fausserait les quantités) :
     *
     *  - les lignes des recettes de l'utilisateur qui utilisent `B` passent sur `A` (une recette qui contenait les deux garde ses deux
     *    lignes) ;
     *  - dans chaque liste de l'utilisateur qui utilise `B` : si `A` y a déjà une ligne, elle reçoit la quantité de `B` ré-exprimée
     *    dans son unité (somme), et reste cochée seulement si les deux l'étaient ; sinon la ligne de `B` devient une ligne de `A`
     *    (quantité ré-exprimée dans l'unité d'achat de `A`, cochage inchangé). Les ingrédients ajoutés à la main (compteurs de pas
     *    entiers) suivent la même règle, arrondis au pas supérieur ;
     *  - la version de chaque recette et de chaque liste modifiée monte de 1, celle des autres jamais.
     *
     * Les listes envoyées au drive ne sont pas écartées ici : l'appelant les a refusées avant (`MergeBlock::IN_SENT_LIST`).
     *
     * @param list<UnitFactor> $factors les facteurs des changements d'unité de la liste (`IngredientMergePlan::$factors`)
     *
     * @throws IngredientException `INGREDIENT_MERGE_CONFLICT` si une référence à `B` apparaît ou reste après coup (écriture concurrente)
     */
    public function repoint(string $userId, string $absorbedSlug, string $keptSlug, string $keptPurchaseUnitSlug, array $factors): IngredientMergeRepointed;
}
