<?php

namespace Repas\Repas\Application\Query\GetSeedingReport;

/**
 * La requête de santé de l'amorçage (`--check` de `repas:ingredients:seed`, `docs/ingredients/sans-commun.md` § 5) : il n'y a plus de filet
 * (l'adoption à la première utilisation a disparu), donc un compte actif sans ses ingrédients serait aveugle. Des nombres seulement.
 *
 * Tant qu'il reste des ingrédients sans créateur (avant la passation, ING-009), le propriétaire du catalogue utilise légitimement ces anciens
 * communs : il ne compte pas dans les contrôles 2 et 4, qui s'appliquent à lui dès qu'il n'en reste aucun.
 */
final readonly class SeedingHealthDTO
{
    /**
     * @param int $activeNotSeeded 1. comptes actifs, hors propriétaire, sans aucun alias d'adoption
     * @param int $activeWithoutIngredient 2. comptes actifs sans aucun ingrédient à eux (le propriétaire dès qu'aucun ingrédient sans créateur n'existe)
     * @param array<string, int> $foreignLines 3. lignes (recettes, lignes de liste, ingrédients de liste, choix du drive) d'un compte autre que le
     *        propriétaire qui désignent un ingrédient qui n'est pas à lui (un autre compte, ou aucun), par table ; seulement les non nulles
     * @param ?array<string, int> $ownerForeignLines 4. la même chose pour le propriétaire, seulement quand aucun ingrédient sans créateur n'existe ;
     *        `null` : sans objet avant la passation
     * @param int $ingredientsWithoutCreator les anciens communs qui restent
     */
    public function __construct(
        public int $activeNotSeeded,
        public int $activeWithoutIngredient,
        public array $foreignLines,
        public ?array $ownerForeignLines,
        public int $ingredientsWithoutCreator,
    ) {
    }

    public function isHealthy(): bool
    {
        return $this->activeNotSeeded === 0
            && $this->activeWithoutIngredient === 0
            && $this->foreignLines === []
            && ($this->ownerForeignLines ?? []) === [];
    }
}
