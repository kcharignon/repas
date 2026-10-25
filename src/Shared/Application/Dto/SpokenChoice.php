<?php

namespace Repas\Shared\Application\Dto;

/**
 * Un rayon ou une unité que l'utilisateur peut nommer à voix haute pour créer un ingrédient (`SpokenIngredientCreator`, ADR 0029
 * décision 11, VOX-303) : son slug (la clé du catalogue commun) et son nom.
 */
final readonly class SpokenChoice
{
    public function __construct(
        public string $slug,
        public string $name,
    ) {
    }
}
