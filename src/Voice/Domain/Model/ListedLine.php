<?php

namespace Repas\Voice\Domain\Model;

/**
 * Une ligne de la liste de courses active de l'utilisateur telle que la commande vocale la lit (ADR 0029 décision 12, VOX-304) : l'ingrédient (slug, nom, rayon),
 * la quantité de la ligne et son unité.
 *
 * Une PARTIE d'ingrédient (« jaune d'œuf », ADR 0038 décision 33) n'est jamais une ligne de la liste, mais la liste en garde la trace : elle est lue comme une
 * ligne de la partie (sa quantité, son unité) dont `$foldedInto` dit la ligne du parent où elle compte.
 */
final readonly class ListedLine
{
    public function __construct(
        public string $slug,
        public string $name,
        public string $departmentSlug,
        public string $departmentName,
        /** La quantité de la ligne, dans son unité. */
        public float $quantity,
        public string $unitSlug,
        /** Le nom de l'unité, tel que le catalogue l'écrit (« paquet »). */
        public string $unitName,
        /** Le nom de la liste d'un TYPE qui porte la ligne (ADR 0036, LST-008) ; `null` : la liste principale. */
        public ?string $listName = null,
        /** La ligne du parent où compte cette partie d'ingrédient ; `null` : un ingrédient ordinaire. */
        public ?ParentLine $foldedInto = null,
    ) {
    }
}
