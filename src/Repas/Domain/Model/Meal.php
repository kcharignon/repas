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
    ) {
    }



    /**
     * @throws MealException le nombre de personnes est hors de 1 à 100
     */
    public static function create(string $id, string $shoppingListId, string $recipeId, int $servings): self
    {
        self::assertServing($servings);

        return new self(
            id: $id,
            shoppingListId: $shoppingListId,
            recipeId: $recipeId,
            serving: $servings
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
        );
    }

    /**
     * L'identifiant de la planification qui a créé ce repas (ADR 0036) : les repas d'une même planification éclatée entre plusieurs listes le
     * partagent. `null` pour un repas planifié seul (tous ceux d'avant les types). Rien ne le pose ni ne le lit encore (LST-005).
     */
    public function getPlannedGroupId(): ?string
    {
        return $this->plannedGroupId;
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
