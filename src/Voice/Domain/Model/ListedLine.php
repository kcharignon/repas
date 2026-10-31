<?php

namespace Repas\Shared\Application\Dto;

/**
 * Une ligne de la liste de courses active de l'utilisateur telle que la commande vocale la lit (ADR 0029 décision 12, VOX-304) : un simple objet
 * de transport entre le contexte `Repas` et le contexte `Voice`.
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
    ) {
    }
}
