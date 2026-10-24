<?php

namespace Repas\Repas\Application\Query\GetSeedingReport;

/**
 * La requête de santé de l'amorçage (`--check` de `repas:ingredients:seed`, `docs/ingredients/sans-commun.md` § 5) : il n'y a plus de filet
 * (l'adoption à la première utilisation a disparu), donc un compte actif sans ses ingrédients serait aveugle. Des nombres seulement.
 *
 * Le propriétaire du catalogue compte dans les contrôles 2 et 4 dès qu'il existe : la passation des anciens communs est faite (ING-009) et un
 * ingrédient est toujours à quelqu'un.
 */
final readonly class SeedingHealthDTO
{
    /**
     * @param int $activeNotSeeded 1. comptes actifs, hors propriétaire, sans aucun alias d'adoption
     * @param int $activeWithoutIngredient 2. comptes actifs sans aucun ingrédient à eux (le propriétaire compris)
     * @param array<string, int> $foreignLines 3. lignes (recettes, lignes de liste, ingrédients de liste, choix du drive) d'un compte autre que le
     *        propriétaire qui désignent un ingrédient qui n'est pas à lui (un autre compte, ou aucun), par table ; seulement les non nulles
     * @param ?array<string, int> $ownerForeignLines 4. la même chose pour le propriétaire ; `null` : sans objet, il n'y a pas de propriétaire du catalogue
     * @param int $ingredientsWithoutCreator 5. ingrédients dont le créateur n'est aucun compte existant (attendu : 0)
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
            && ($this->ownerForeignLines ?? []) === []
            && $this->ingredientsWithoutCreator === 0;
    }
}
