<?php

namespace Repas\Repas\Application\OpenHostService;

/**
 * Une ligne d'une liste de courses en cours d'un utilisateur, publiée par `Repas` pour un autre contexte : l'ingrédient (slug, nom, rayon), la
 * quantité de la ligne et son unité, et la liste qui la porte (ADR 0036, LST-008). Jamais un modèle de domaine.
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
        /** Le nom de la liste qui porte la ligne quand ce n'est pas la liste principale ; `null` : la liste principale. */
        public ?string $listName = null,
    ) {
    }
}
