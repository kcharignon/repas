<?php

namespace Repas\Voice\Domain\Model;

/**
 * L'ingrédient que l'utilisateur désigne par une phrase prononcée (ADR 0029, VOX-102), tel que `IngredientResolutionCommandPort` le donne à
 * ce contexte.
 */
final readonly class ResolvedIngredient
{
    /** Les trois confiances (voir `$confidence`). */
    public const string SURE = 'sure';
    public const string PROBABLE = 'probable';
    public const string UNSURE = 'unsure';

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
