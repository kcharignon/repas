<?php

namespace Repas\Repas\Infrastructure\Entity;

use Doctrine\ORM\Mapping as ORM;
use Repas\Repas\Domain\Interface\MealRepository;
use Repas\Repas\Domain\Model\Meal as MealModel;

#[ORM\Entity(repositoryClass: MealRepository::class)]
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

    public function __construct(
        ?string $id,
        ?string $shoppingListId,
        ?string $recipeId,
        ?int $serving
    ) {
        $this->id = $id;
        $this->shoppingListId = $shoppingListId;
        $this->recipeId = $recipeId;
        $this->serving = $serving;
    }

    public static function fromModel(MealModel $meal): static
    {
        return new self(
            id: $meal->getId(),
            shoppingListId: $meal->getShoppingListId(),
            recipeId: $meal->getRecipe()->getId(),
            serving: $meal->getServing(),
        );
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
}
