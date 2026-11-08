<?php

namespace Repas\Repas\Application\OpenHostService;

/**
 * La ligne du parent où une partie d'ingrédient compte (ADR 0038 décision 33), publiée par `Repas` pour un autre contexte : son nom, son total dans la
 * liste et l'unité de cette ligne. Jamais un modèle de domaine.
 */
final readonly class ActiveListParentDTO
{
    public function __construct(
        public string $name,
        /** La quantité de la ligne du parent, dans son unité. */
        public float $quantity,
        public string $unitSlug,
        /** Le nom de l'unité de la ligne du parent, tel que le catalogue l'écrit. */
        public string $unitName,
    ) {
    }
}
