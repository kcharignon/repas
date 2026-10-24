<?php

namespace Repas\Repas\Domain\Model;


/**
 * Le candidat sur lequel brancher (`IngredientNameMatcher::match()`), avec la raison : `EXACT` (nom normalisé identique, score 1) ou
 * `TYPO` (faute de frappe, Jaro-Winkler de 0,97 au moins).
 */
final readonly class IngredientMatch
{
    public function __construct(
        public IngredientCandidate $candidate,
        public MatchLevel $level,
        public float $score,
    ) {
    }
}
