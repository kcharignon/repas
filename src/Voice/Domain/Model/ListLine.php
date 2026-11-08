<?php

namespace Repas\Voice\Domain\Model;

use Repas\Voice\Domain\Service\SpokenUnits;

/**
 * Une ligne de la liste de courses active, telle qu'Alexa la lit à voix haute (ADR 0029 décision 12) : le nom de l'ingrédient, son rayon, la
 * quantité de la ligne et le nom de son unité. Une PARTIE d'ingrédient (« jaune d'œuf », ADR 0038 décision 33) dit en plus la ligne du parent où elle compte.
 */
final readonly class ListLine
{
    public function __construct(
        public string $name,
        public string $departmentName,
        public float $quantity,
        public string $unitSlug,
        public string $unitName,
        /** Le nom de la liste d'un TYPE qui porte la ligne (ADR 0036, LST-008) ; `null` : la liste principale. */
        public ?string $listName = null,
        /** La ligne du parent où compte cette partie d'ingrédient ; `null` : un ingrédient ordinaire. */
        public ?ParentLine $foldedInto = null,
    ) {
    }

    /** L'unité de la ligne est l'unité « unité » : elle ne se dit pas (« 3 croquettes », pas « 3 unités de croquettes »). */
    public function isCount(): bool
    {
        return $this->unitSlug === SpokenUnits::COUNT;
    }
}
