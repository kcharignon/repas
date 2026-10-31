<?php

namespace Repas\Repas\Domain\Model;


use Repas\Repas\Domain\Exception\MealException;
use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;

final class Meal implements ModelInterface
{
    use ModelTrait;

    /** Nombre de personnes d'un repas : de 1 à 100, comme celui d'une recette (`Recipe`) et celui d'un compte. */
    public const int MIN_SERVING = 1;
    public const int MAX_SERVING = 100;

    private function __construct(
        private string $id,
        private string $shoppingListId,
        private string $recipeId,
        private int    $serving,
        private ?string $plannedGroupId = null,
        private ?array $placedIngredients = null,
    ) {
    }

    /**
     * @param ?string       $plannedGroupId    le groupe de la planification quand la recette est éclatée entre plusieurs listes (ADR 0036
     *                                         décision 9), `null` pour un repas planifié seul
     * @param ?list<string> $placedIngredients les slugs des produits de la recette que ce repas pose dans SA liste (voir `places()`), `null`
     *                                         pour un repas qui pose toute la recette
     *
     * @throws MealException le nombre de personnes est hors de 1 à 100
     */
    public static function create(string $id, string $shoppingListId, string $recipeId, int $servings, ?string $plannedGroupId = null, ?array $placedIngredients = null): self
    {
        self::assertServing($servings);

        return new self(
            id: $id,
            shoppingListId: $shoppingListId,
            recipeId: $recipeId,
            serving: $servings,
            plannedGroupId: $plannedGroupId,
            placedIngredients: $placedIngredients === null ? null : array_values(array_unique($placedIngredients)),
        );
    }

    public static function load(array $datas): self
    {
        return new self(
            id: $datas['id'],
            shoppingListId: $datas['shopping_list_id'],
            recipeId: $datas['recipe_id'],
            serving: $datas['serving'],
            plannedGroupId: $datas['planned_group_id'] ?? null,
            placedIngredients: $datas['placed_ingredients'] ?? null,
        );
    }

    /**
     * L'identifiant de la planification qui a créé ce repas (ADR 0036 décision 9) : les repas d'une même planification éclatée entre plusieurs
     * listes le partagent, et changer les personnes ou retirer la recette agit sur tout le groupe. `null` pour un repas planifié seul (tous ceux
     * d'avant les types, et ceux dont la recette n'a rempli qu'une liste).
     */
    public function getPlannedGroupId(): ?string
    {
        return $this->plannedGroupId;
    }

    /**
     * Les produits de la recette que ce repas a posés dans sa liste, figés à la planification (ADR 0036 décision 5 : changer la destination d'un
     * produit ensuite ne déplace aucune ligne, donc retirer ou ajuster le repas ne regarde jamais la destination d'aujourd'hui). `null` : toute
     * la recette (un repas planifié seul, comme avant les types).
     *
     * @return ?list<string>
     */
    public function getPlacedIngredients(): ?array
    {
        return $this->placedIngredients;
    }

    /**
     * Ce repas a-t-il posé ce produit dans sa liste ? Vrai pour tout produit d'un repas qui a posé toute la recette.
     */
    public function places(string $ingredientSlug): bool
    {
        return $this->placedIngredients === null || in_array($ingredientSlug, $this->placedIngredients, true);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getShoppingListId(): string
    {
        return $this->shoppingListId;
    }

    public function getRecipeId(): string
    {
        return $this->recipeId;
    }

    public function getServing(): int
    {
        return $this->serving;
    }

    public function hasRecipe(Recipe $recipe): bool
    {
        return $this->recipeId === $recipe->getId();
    }

    /**
     * @throws MealException le nombre de personnes est hors de 1 à 100
     */
    public function setServing(int $serving): void
    {
        self::assertServing($serving);
        $this->serving = $serving;
    }

    private static function assertServing(int $serving): void
    {
        if ($serving < self::MIN_SERVING || $serving > self::MAX_SERVING) {
            throw MealException::invalidServing();
        }
    }
}
