<?php

namespace Repas\Repas\Application\OpenHostService;

/**
 * Une ligne de la liste de courses active d'un utilisateur, publiée par `Repas` pour un autre contexte : l'ingrédient (slug, nom, rayon), la
 * quantité de la ligne et son unité. Jamais un modèle de domaine.
 */
final readonly class ActiveListLineDTO
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
