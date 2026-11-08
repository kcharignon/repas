<?php

namespace Repas\Repas\Domain\Model;


/**
 * Ce que la fusion de l'ingrédient absorbé `B` dans l'ingrédient gardé `A` touchera chez l'utilisateur, lu sans rien écrire
 * (`docs/ingredients/fusion.md` §§ 3 et 5). Des nombres, des slugs d'unités et des booléens (ADR 0013).
 */
final readonly class IngredientMergeUsage
{
    /**
     * @param int $recipeCount les recettes de l'utilisateur qui utilisent `B` (celles qui passent sur `A`)
     * @param int $listCount les listes de courses de l'utilisateur qui utilisent `B` (une ligne ou un ingrédient ajouté à la main)
     * @param list<string> $recipeUnitSlugs les unités des lignes de ses recettes qui utilisent `B`, sans doublon : chacune doit se convertir
     *        dans l'unité d'achat de `A`
     * @param list<MergeUnitMove> $listMoves les changements d'unité des quantités de ses listes, sans doublon (identités comprises)
     * @param int $sentListCount ses listes envoyées au drive qui utilisent `A` ou `B`
     * @param bool $usedByAnotherUser une ligne d'un AUTRE utilisateur (recette ou liste) référence `B`
     * @param int $resultRecipeCount ses recettes DISTINCTES qui utiliseront `A` APRÈS la fusion : celles qui utilisent `A`, celles qui utilisent
     *        `B`, une recette qui utilise les deux comptée une fois
     * @param int $resultListCount ses listes DISTINCTES qui utiliseront `A` après la fusion (même union, une ligne ou un ajout à la main)
     * @param list<MergeConversion> $absorbedConversions les conversions PROPRES de `B`, que `A` peut récupérer (`IngredientMergePlanner`)
     * @param int $absorbedPartCount combien de parties a `B` (ADR 0038), onze au plus : le plafond de dix est dépassé dès la onzième
     * @param int $keptPartCount combien de parties a `A`, onze au plus
     * @param bool $partRegistryMentioned le registre des parties d'une liste NON TERMINÉE nomme `A` ou `B`, en partie ou en parent : la fusion ne
     *        réécrit jamais ce registre (`MergeBlock::PART_IN_LIST`)
     * @param bool $openListLine `A` ou `B` a une ligne dans une liste non terminée : si le gardé est une partie après la fusion, une partie serait
     *        une ligne de liste en cours, ce qu'elle n'est jamais (`MergeBlock::PART_IN_LIST`)
     */
    public function __construct(
        public int $recipeCount = 0,
        public int $listCount = 0,
        public array $recipeUnitSlugs = [],
        public array $listMoves = [],
        public int $sentListCount = 0,
        public bool $usedByAnotherUser = false,
        public int $resultRecipeCount = 0,
        public int $resultListCount = 0,
        public array $absorbedConversions = [],
        public int $absorbedPartCount = 0,
        public int $keptPartCount = 0,
        public bool $partRegistryMentioned = false,
        public bool $openListLine = false,
    ) {
    }
}
