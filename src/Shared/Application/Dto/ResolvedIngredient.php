<?php

namespace Repas\Shared\Application\Dto;

/**
 * L'ingrédient que `SpokenIngredientResolver` retient pour une phrase prononcée (ADR 0029, VOX-102) : un simple objet de transport entre le
 * contexte `Repas` et le contexte `Voice`.
 */
final readonly class ResolvedIngredient
{
    /**
     * @param string $confidence `sure`, `probable` ou `unsure` (voir `docs/voice/alexa.md` § 4.5) : comment l'annoncer à l'utilisateur
     */
    public function __construct(
        public string $slug,
        public string $name,
        public string $departmentName,
        public string $confidence,
    ) {
    }
}
