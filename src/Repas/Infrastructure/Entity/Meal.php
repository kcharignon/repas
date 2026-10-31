<?php

namespace Repas\Repas\Infrastructure\Entity;

use Doctrine\ORM\Mapping as ORM;
use Repas\Repas\Domain\Model\Meal as MealModel;

#[ORM\Entity]
#[ORM\Table(name: 'meal')]
class Meal
{
    #[ORM\Id]
    #[ORM\Column(length: 255, unique: true)]
    private ?string $id = null;


    #[ORM\Column(name: 'shopping_list', nullable: false)]
    private ?string $shoppingListId = null;

    #[ORM\Column(name: 'recipe', nullable: false)]
    private ?string $recipeId = null;

    #[ORM\Column]
    private ?int $serving = null;

    // Identifiant de la planification qui a créé le repas (ADR 0036 décision 9) ; null = un repas planifié seul
    #[ORM\Column(name: 'planned_group_id', length: 36, nullable: true)]
    private ?string $plannedGroupId = null;

    /**
     * Les slugs des produits de la recette que ce repas a posés dans sa liste (LST-005, figés à la planification) ; null = toute la recette.
     *
     * @var ?list<string>
     */
    #[ORM\Column(name: 'placed_ingredients', type: 'json', nullable: true, options: ['jsonb' => true])]
    private ?array $placedIngredients = null;

    /**
     * @param ?list<string> $placedIngredients
     */
    public function __construct(
        ?string $id,
        ?string $shoppingListId,
        ?string $recipeId,
        ?int $serving,
        ?string $plannedGroupId = null,
        ?array $placedIngredients = null,
    ) {
        $this->id = $id;
        $this->shoppingListId = $shoppingListId;
        $this->recipeId = $recipeId;
        $this->serving = $serving;
        $this->plannedGroupId = $plannedGroupId;
        $this->placedIngredients = $placedIngredients;
    }

    public static function fromModel(MealModel $meal): static
    {
        return new self(
            id: $meal->getId(),
            shoppingListId: $meal->getShoppingListId(),
            recipeId: $meal->getRecipeId(),
            serving: $meal->getServing(),
            plannedGroupId: $meal->getPlannedGroupId(),
            placedIngredients: $meal->getPlacedIngredients(),
        );
    }

    public function updateFromModel(MealModel $mealModel): void
    {
        $this->serving = $mealModel->getServing();
        $this->plannedGroupId = $mealModel->getPlannedGroupId();
        $this->placedIngredients = $mealModel->getPlacedIngredients();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getShoppingListId(): ?string
    {
        return $this->shoppingListId;
    }

    public function setShoppingListId(?string $shoppingListId): static
    {
        $this->shoppingListId = $shoppingListId;

        return $this;
    }

    public function getRecipeId(): ?string
    {
        return $this->recipeId;
    }

    public function setRecipeId(?string $recipeId): static
    {
        $this->recipeId = $recipeId;

        return $this;
    }

    public function getServing(): ?int
    {
        return $this->serving;
    }

    public function setServing(int $serving): static
    {
        $this->serving = $serving;

        return $this;
    }

    public function getPlannedGroupId(): ?string
    {
        return $this->plannedGroupId;
    }

    /**
     * @return ?list<string>
     */
    public function getPlacedIngredients(): ?array
    {
        return $this->placedIngredients;
    }
}
