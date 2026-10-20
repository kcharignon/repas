<?php

namespace Repas\Repas\Domain\Model;


/**
 * Le verdict de la fusion de deux ingrédients et ce qu'elle ferait des unités (`IngredientMergePlanner`) : pourquoi elle serait refusée,
 * le cas échéant, les unités qui ne se convertissent pas, les facteurs qui ré-expriment les quantités des listes.
 */
final readonly class IngredientMergePlan
{
    /**
     * @param ?MergeBlock $block le premier refus, `null` quand la fusion est possible
     * @param list<string> $missingUnitSlugs les unités qui ne se convertissent pas par l'ingrédient gardé, triées
     * @param list<UnitFactor> $factors les facteurs des changements d'unité des listes (jamais une identité)
     */
    public function __construct(
        public ?MergeBlock $block,
        public array $missingUnitSlugs = [],
        public array $factors = [],
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
