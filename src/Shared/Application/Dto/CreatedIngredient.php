<?php

namespace Repas\Shared\Application\Dto;

/**
 * L'ingrédient que `SpokenIngredientCreator::create()` vient de créer pour l'utilisateur (ADR 0029 décision 11, VOX-303).
 */
final readonly class CreatedIngredient
{
    public function __construct(
        public string $slug,
        public string $name,
        public string $departmentName,
    ) {
    }
}
