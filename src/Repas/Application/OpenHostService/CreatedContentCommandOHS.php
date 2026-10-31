<?php

namespace Repas\Repas\Application\OpenHostService;

use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Model\Ingredient;

/**
 * Open Host Service d'écriture du contexte `Repas` (ADR 0035) : ce qu'un utilisateur a créé, pour recompter ses statistiques (commande
 * `repas:update-users-statistics`). Des nombres seulement, jamais un agrégat.
 */
final readonly class CreatedContentCommandOHS
{
    public function __construct(
        private IngredientRepository $ingredients,
        private RecipeRepository $recipes,
    ) {
    }

    /**
     * Les ingrédients créés par l'utilisateur : une copie d'adoption (activation, copie d'une recette, fusion) n'est pas une création
     * (ADR 0028 décision 7).
     */
    public function createdIngredients(string $userId): int
    {
        return $this->ingredients->findByOwner($userId)
            ->filter(fn(Ingredient $ingredient): bool => $ingredient->getCreatorId() === $userId && !$ingredient->isAdoption())
            ->count();
    }

    /** Les recettes dont l'utilisateur est l'auteur, copies comprises. */
    public function recipes(string $userId): int
    {
        return $this->recipes->findByAuthor($userId)->count();
    }
}
