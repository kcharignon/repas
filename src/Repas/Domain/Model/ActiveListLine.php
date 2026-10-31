<?php

namespace Repas\Repas\Domain\Model;

/**
 * Une ligne de la liste de courses active de l'utilisateur, telle que `ActiveListLookup` la cherche (ADR 0029 décision 12, VOX-304) : l'ingrédient
 * (slug, nom, rayon), la quantité de la ligne et son unité. Une valeur lue, jamais un agrégat.
 */
final readonly class ActiveListLine
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
    ) {
    }
}
