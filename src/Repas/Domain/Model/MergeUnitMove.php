<?php

namespace Repas\Repas\Domain\Model;


/**
 * Une quantité de liste de courses exprimée dans `$fromUnitSlug` qui doit l'être dans `$toUnitSlug` quand l'ingrédient absorbé passe sur
 * l'ingrédient gardé (`docs/ingredients/fusion.md` § 3, étapes 2 et 3).
 */
final readonly class MergeUnitMove
{
    public function __construct(
        public string $fromUnitSlug,
        public string $toUnitSlug,
    ) {
    }

    public function isIdentity(): bool
    {
        return $this->fromUnitSlug === $this->toUnitSlug;
    }
}
