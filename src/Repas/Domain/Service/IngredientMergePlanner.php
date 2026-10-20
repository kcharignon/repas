<?php

namespace Repas\Repas\Domain\Service;


use Repas\Repas\Domain\Model\IngredientMergePlan;
use Repas\Repas\Domain\Model\IngredientMergeUsage;
use Repas\Repas\Domain\Model\MergeBlock;
use Repas\Repas\Domain\Model\MergeParty;
use Repas\Repas\Domain\Model\UnitFactor;

/**
 * Le verdict de la fusion de l'ingrédient absorbé `B` dans l'ingrédient gardé `A` et ce qu'elle ferait des unités (ADR 0028 décision 9,
 * `docs/ingredients/fusion.md` §§ 2 et 5). Pur : des slugs, des booléens, le graphe de conversion de `A`. L'aperçu (`GetMergePreview`)
 * et l'écriture (`MergeIngredientsHandler`) l'appellent avec les mêmes faits : l'aperçu dit donc exactement ce que la fusion ferait.
 *
 * Les unités qui doivent suivre :
 *
 *  - chaque unité des lignes de RECETTE de `B` doit se convertir dans l'unité d'achat de `A` par le graphe de `A`, car le calcul d'une
 *    liste convertit chaque ligne de recette dans l'unité d'achat de son ingrédient ; la quantité de la recette, elle, ne change pas ;
 *  - chaque changement d'unité des quantités de LISTE (`IngredientMergeUsage::$listMoves`) doit se convertir aussi, et le facteur est
 *    celui que la fusion applique aux quantités (jamais l'identité, qui n'a pas de facteur).
 *
 * Une unité qui ne se convertit pas arrête la fusion entière (`MergeBlock::UNIT_MISSING`) : rien n'est reporté en silence de `B` sur `A`.
 *
 * Premier refus dans l'ordre de `MergeBlock` : le même ingrédient, un commun, une ligne d'un autre utilisateur, une liste envoyée au
 * drive, une unité qui manque. Les unités manquantes sont rendues même quand un refus plus haut les masque (une liste envoyée, par
 * exemple) : l'utilisateur sait tout ce qui l'attend.
 */
final readonly class IngredientMergePlanner
{
    /**
     * @param bool $chosenByAnotherUser un AUTRE utilisateur a un choix du drive sur `B` (donnée héritée)
     */
    public function plan(
        MergeParty $absorbed,
        MergeParty $kept,
        ConversionGraph $keptGraph,
        IngredientMergeUsage $usage,
        bool $chosenByAnotherUser = false,
    ): IngredientMergePlan {
        if ($absorbed->slug === $kept->slug) {
            return new IngredientMergePlan(MergeBlock::SAME_INGREDIENT);
        }
        if ($absorbed->isCommon || $kept->isCommon) {
            return new IngredientMergePlan(MergeBlock::COMMON_INGREDIENT);
        }

        $missing = [];
        foreach ($usage->recipeUnitSlugs as $unitSlug) {
            if ($unitSlug !== $kept->purchaseUnitSlug && $keptGraph->coefficient($unitSlug, $kept->purchaseUnitSlug) === null) {
                $missing[$unitSlug] = $unitSlug;
            }
        }

        $factors = [];
        foreach ($usage->listMoves as $move) {
            if ($move->isIdentity()) {
                continue;
            }
            $factor = $keptGraph->coefficient($move->fromUnitSlug, $move->toUnitSlug);
            if ($factor === null) {
                $missing[$move->fromUnitSlug] = $move->fromUnitSlug;
            } else {
                $factors[] = new UnitFactor($move->fromUnitSlug, $move->toUnitSlug, $factor);
            }
        }
        ksort($missing);

        $block = match (true) {
            $usage->usedByAnotherUser || $chosenByAnotherUser => MergeBlock::USED_BY_ANOTHER_USER,
            $usage->sentListCount > 0 => MergeBlock::IN_SENT_LIST,
            $missing !== [] => MergeBlock::UNIT_MISSING,
            default => null,
        };

        return new IngredientMergePlan($block, array_values($missing), $factors);
    }
}
