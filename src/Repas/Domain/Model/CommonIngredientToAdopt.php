<?php

namespace Repas\Repas\Domain\Model;


/**
 * Un commun qu'un utilisateur n'a pas encore adopté (ADR 0028, migration ING-003) : son slug et son nom, de quoi calculer le slug de la
 * copie (`IngredientSlugPolicy::forAdoption`). Une valeur, pas un agrégat : le catalogue des communs n'est jamais hydraté.
 *
 * `existingCopySlug` : l'utilisateur a DÉJÀ un ingrédient né de ce commun (`adopted_from`) et strictement conforme à lui (retour arrière
 * d'un lancement, copie de recette qui a retenu l'origine) mais sans alias : l'adoption le désigne au lieu de créer un doublon.
 */
final readonly class CommonIngredientToAdopt
{
    public function __construct(
        public string $slug,
        public string $name,
        public ?string $existingCopySlug = null,
    ) {
    }
}
