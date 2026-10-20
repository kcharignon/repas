<?php

namespace Repas\Repas\Application\Command\AdoptCommonIngredient;


/**
 * Adopter un ingrédient commun pour un utilisateur (ADR 0028) : lui en créer une copie à lui, avec ses conversions propres, et noter
 * la correspondance. Le handler renvoie le slug de l'ingrédient de l'utilisateur, celui qui vient d'être créé ou celui que l'alias
 * désignait déjà. Aucun controller ne l'appelle encore : elle sert à la résolution des slugs reçus en écriture (ING-002), à la
 * migration (ING-003) et à la copie de recette (ING-004).
 */
final readonly class AdoptCommonIngredientCommand
{
    /**
     * @param string $source d'où vient l'adoption, écrit dans l'alias : `first-use`, `copy` ou `migration-AAAA-MM-JJ` (64 caractères au plus)
     */
    public function __construct(
        public string $userId,
        public string $commonSlug,
        public string $source,
    ) {
    }
}
