<?php

namespace Repas\Repas\Domain\Service\Marmiton;


/**
 * Une ligne d'ingrédient de Marmiton comprise par Repas (ADR 0037 décision 7) : une quantité, une unité de référence et le nom du produit.
 * La ligne d'origine est gardée pour qui voudrait la montrer ; rien d'autre n'en dépend.
 */
final readonly class ParsedIngredientLine
{
    /**
     * @param float  $quantity quantité strictement positive, dans les bornes de `QuantityBounds` (1 quand la ligne n'en dit pas de lisible)
     * @param string $unitSlug un slug de `unit.json` (`unite` quand aucune unité n'est reconnue) ou `pincee`
     * @param string $name     le nom du produit, propre, jamais vide, 100 caractères au plus, casse d'origine
     * @param string $raw      la ligne telle que reçue (255 caractères au plus)
     */
    public function __construct(
        public float $quantity,
        public string $unitSlug,
        public string $name,
        public string $raw,
    ) {
    }
}
