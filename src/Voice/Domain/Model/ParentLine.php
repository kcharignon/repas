<?php

namespace Repas\Voice\Domain\Model;

use Repas\Voice\Domain\Service\SpokenUnits;

/**
 * La ligne du PARENT où compte une partie d'ingrédient que la liste a gardée (« jaune d'œuf » compte dans les 5 œufs de la liste, ADR 0038 décision 33) :
 * son nom, son total dans la liste et l'unité de cette ligne, de quoi dire « ils comptent dans tes 5 œufs ».
 */
final readonly class ParentLine
{
    public function __construct(
        public string $name,
        /** La quantité de la ligne du parent, dans son unité. */
        public float $quantity,
        public string $unitSlug,
        /** Le nom de l'unité de la ligne du parent, tel que le catalogue l'écrit (« paquet »). */
        public string $unitName,
    ) {
    }

    /** L'unité de la ligne est l'unité « unité » : elle ne se dit pas (« 5 œufs », pas « 5 unités d'œuf »). */
    public function isCount(): bool
    {
        return $this->unitSlug === SpokenUnits::COUNT;
    }
}
