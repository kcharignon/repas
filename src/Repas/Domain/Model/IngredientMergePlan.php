<?php

namespace Repas\Repas\Domain\Model;


/**
 * Le verdict de la fusion de deux ingrédients et ce qu'elle ferait des unités (`IngredientMergePlanner`) : pourquoi elle serait refusée,
 * le cas échéant, les unités qui ne se convertissent pas (même après la récupération des conversions), les facteurs qui ré-expriment les
 * quantités des listes, les conversions que l'ingrédient gardé récupère de l'absorbé, les unités compatibles qu'il y gagne.
 */
final readonly class IngredientMergePlan
{
    /**
     * @param ?MergeBlock $block le premier refus, `null` quand la fusion est possible
     * @param list<string> $missingUnitSlugs les unités qui ne se convertissent pas par l'ingrédient gardé, triées, APRÈS la récupération
     * @param list<UnitFactor> $factors les facteurs des changements d'unité des listes (jamais une identité)
     * @param list<MergeConversion> $recoveredConversions les conversions propres de l'absorbé que le gardé n'a pas et qui lui sont copiées
     *        (vide quand la fusion est refusée : rien n'est écrit)
     * @param list<string> $compatibleUnitSlugsToAdd les unités compatibles de l'absorbé, utilisées par les lignes de recette déplacées, que
     *        le gardé n'avait pas et qui s'ajoutent aux siennes (vide quand la fusion est refusée)
     */
    public function __construct(
        public ?MergeBlock $block,
        public array $missingUnitSlugs = [],
        public array $factors = [],
        public array $recoveredConversions = [],
        public array $compatibleUnitSlugsToAdd = [],
    ) {
    }

    public function canMerge(): bool
    {
        return $this->block === null;
    }

    /**
     * Des quantités de liste changent d'unité (l'unité d'achat de l'ingrédient gardé n'est pas celle de l'absorbé).
     */
    public function listQuantitiesConverted(): bool
    {
        return $this->factors !== [];
    }
}
