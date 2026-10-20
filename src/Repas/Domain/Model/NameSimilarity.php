<?php

namespace Repas\Repas\Domain\Model;


/**
 * La ressemblance de deux noms d'ingrédients qui passent le garde-fou du rapprochement (`IngredientNameMatcher::compare()`) : le niveau
 * (`EXACT`, `TYPO` ou `SUGGESTION`, jamais `NONE`) et le score de Jaro-Winkler des noms normalisés, de 0,90 à 1.
 */
final readonly class NameSimilarity
{
    public function __construct(
        public MatchLevel $level,
        public float $score,
    ) {
    }
}
