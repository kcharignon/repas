<?php

namespace Repas\Voice\Domain\Model;

use Repas\Voice\Domain\Service\SpokenUnits;

/**
 * Une ligne de la liste de courses active, telle qu'Alexa la lit à voix haute (ADR 0029 décision 12) : le nom de l'ingrédient, son rayon, la
 * quantité de la ligne et le nom de son unité.
 */
final readonly class ListLine
{
    public function __construct(
        public string $name,
        public string $departmentName,
        public float $quantity,
        public string $unitSlug,
        public string $unitName,
    ) {
    }

    /** L'unité de la ligne est l'unité « unité » : elle ne se dit pas (« 3 croquettes », pas « 3 unités de croquettes »). */
    public function isCount(): bool
    {
        return $this->unitSlug === SpokenUnits::COUNT;
    }
}
