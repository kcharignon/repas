<?php

namespace Repas\Repas\Domain\Model;


/**
 * L'ingrédient retenu pour une phrase prononcée, avec son score et la confiance qu'on lui accorde (`SpokenIngredientRanker`).
 */
final readonly class RankedIngredient
{
    public function __construct(
        public string $slug,
        public string $name,
        public string $departmentName,
        public float $score,
        public Confidence $confidence,
        public string $departmentSlug = '',
    ) {
    }
}
