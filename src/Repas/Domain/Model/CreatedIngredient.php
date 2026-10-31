<?php

namespace Repas\Repas\Domain\Model;


/**
 * L'ingrédient que `IngredientCreationCommandOHS::create()` vient de créer pour un utilisateur : son slug, le nom tel qu'il a été demandé
 * et le nom de son rayon (de quoi le dire à voix haute). Une valeur de résultat, pas l'agrégat.
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
