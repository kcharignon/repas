<?php

namespace Repas\Repas\Domain\Service;


use Repas\Repas\Domain\Model\IngredientMergePlan;
use Repas\Repas\Domain\Model\IngredientMergeUsage;
use Repas\Repas\Domain\Model\MergeBlock;
use Repas\Repas\Domain\Model\MergeConversion;
use Repas\Repas\Domain\Model\MergeParty;
use Repas\Repas\Domain\Model\UnitFactor;

/**
 * Le verdict de la fusion de l'ingrédient absorbé `B` dans l'ingrédient gardé `A` et ce qu'elle ferait des conversions et des unités (ADR 0028
 * décision 9 et son amendement du 2026-10-21, `docs/ingredients/fusion.md` §§ 2, 3 et 5). Pur : des slugs, des booléens, le graphe de
 * conversion de `A`. L'aperçu (`GetMergePreview`) et l'écriture (`MergeIngredientsHandler`) l'appellent avec les mêmes faits : l'aperçu dit
 * donc exactement ce que la fusion ferait.
 *
 * D'abord `A` RÉCUPÈRE de `B` les conversions qui lui manquent : une conversion PROPRE de `B` est copiée sur `A` quand ses deux unités ne
 * sont pas déjà reliées dans le graphe de `A` (ses conversions propres, les conversions communes, et celles déjà récupérées). Si elles le
 * sont, le chemin de `A` reste : à l'identique il n'y a rien à copier, avec un autre coefficient `A` GAGNE, on n'écrase jamais ce qu'il
 * sait. Les conversions récupérées ne relient que des unités jusque-là séparées : aucun chemin déjà connu de `A` ne change.
 *
 * Ensuite, dans le graphe ainsi complété, les unités qui doivent suivre :
 *
 *  - chaque unité des lignes de RECETTE de `B` doit se convertir dans l'unité d'achat de `A`, car le calcul d'une liste convertit chaque
 *    ligne de recette dans l'unité d'achat de son ingrédient ; la quantité de la recette, elle, ne change pas ;
 *  - chaque changement d'unité des quantités de LISTE (`IngredientMergeUsage::$listMoves`) doit se convertir aussi, et le facteur est
 *    celui que la fusion applique aux quantités (jamais l'identité, qui n'a pas de facteur).
 *
 * Une unité qui ne se convertit toujours pas arrête la fusion entière (`MergeBlock::UNIT_MISSING`) : c'est alors une vraie impossibilité,
 * aucune des deux fiches ne sait la convertir. Rien n'est récupéré ni ajouté quand la fusion est refusée.
 *
 * Les unités compatibles de `B` que ses lignes de recette déplacées utilisent, et que `A` n'avait pas, s'ajoutent à celles de `A` : l'éditeur
 * de recette les lui propose, et elles sont désormais jointes à son unité d'achat.
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

        [$graph, $recovered] = $this->recover($keptGraph, $usage->absorbedConversions);

        $missing = [];
        foreach ($usage->recipeUnitSlugs as $unitSlug) {
            if ($unitSlug !== $kept->purchaseUnitSlug && $graph->coefficient($unitSlug, $kept->purchaseUnitSlug) === null) {
                $missing[$unitSlug] = $unitSlug;
            }
        }

        $factors = [];
        foreach ($usage->listMoves as $move) {
            if ($move->isIdentity()) {
                continue;
            }
            $factor = $graph->coefficient($move->fromUnitSlug, $move->toUnitSlug);
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
        if ($block !== null) {
            return new IngredientMergePlan($block, array_values($missing), $factors);
        }

        return new IngredientMergePlan(null, [], $factors, $recovered, $this->compatibleUnitsToAdd($absorbed, $kept, $usage));
    }

    /**
     * Les conversions propres de `B` que `A` récupère, dans l'ordre (unité de départ, unité d'arrivée, identifiant : le même résultat quel
     * que soit l'ordre dans lequel on les a lues), et le graphe de `A` complété par elles.
     *
     * @param list<MergeConversion> $absorbedConversions
     * @return array{0: ConversionGraph, 1: list<MergeConversion>}
     */
    private function recover(ConversionGraph $keptGraph, array $absorbedConversions): array
    {
        usort($absorbedConversions, static fn(MergeConversion $x, MergeConversion $y): int => [$x->startUnitSlug, $x->endUnitSlug, $x->id] <=> [$y->startUnitSlug, $y->endUnitSlug, $y->id]);

        $graph = $keptGraph;
        $recovered = [];
        foreach ($absorbedConversions as $conversion) {
            // Un coefficient nul ou négatif n'est pas une conversion utilisable : il ne relie rien (et son inverse n'existerait pas)
            if (!is_finite($conversion->coefficient) || $conversion->coefficient <= 0) {
                continue;
            }
            // Déjà reliées chez `A` : la même chose (rien à copier) ou autre chose (`A` gagne). Dans les deux cas on laisse `A` tel qu'il est.
            if ($graph->coefficient($conversion->startUnitSlug, $conversion->endUnitSlug) !== null) {
                continue;
            }
            $recovered[] = $conversion;
            $graph = $graph->with($conversion->startUnitSlug, $conversion->endUnitSlug, $conversion->coefficient);
        }

        return [$graph, $recovered];
    }

    /**
     * Les unités des lignes de recette déplacées que `B` déclarait compatibles et que `A` ne déclarait pas. Quand la fusion est possible,
     * chacune se convertit en l'unité d'achat de `A` (sinon `UNIT_MISSING` l'aurait arrêtée) : elle est « joignable ».
     *
     * @return list<string> triées
     */
    private function compatibleUnitsToAdd(MergeParty $absorbed, MergeParty $kept, IngredientMergeUsage $usage): array
    {
        $added = [];
        foreach ($usage->recipeUnitSlugs as $unitSlug) {
            if (in_array($unitSlug, $absorbed->compatibleUnitSlugs, true) && !in_array($unitSlug, $kept->compatibleUnitSlugs, true)) {
                $added[$unitSlug] = $unitSlug;
            }
        }
        ksort($added);

        return array_values($added);
    }
}
