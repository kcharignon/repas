<?php

namespace Repas\Repas\Infrastructure\Entity;

use Doctrine\ORM\Mapping as ORM;
use Repas\Repas\Domain\Model\Recipe as RecipeModel;
use Repas\Repas\Domain\Model\RecipeRow as RecipeRowModel;
use Repas\Repository\Repas\Repas\Infrastructure\Entity\RecipeRowRepository;

#[ORM\Entity(repositoryClass: RecipeRowRepository::class)]
class RecipeRow
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Ingredient::class)]
    #[ORM\JoinColumn(name: "ingredient", referencedColumnName: "slug", nullable: false)]
    private ?Ingredient $ingredient = null;

    #[ORM\Column]
    private ?float $quantity = null;

    #[ORM\ManyToOne(targetEntity: Unit::class)]
    #[ORM\JoinColumn(name: "unit", referencedColumnName: "slug", nullable: false)]
    private ?Unit $unit = null;

    #[ORM\ManyToOne(inversedBy: 'rows')]
    #[ORM\JoinColumn(name: 'recipe', nullable: false)]
    private ?Recipe $recipe = null;

    public function __construct(?string $id, ?Ingredient $ingredient, ?float $quantity, ?Unit $unit, ?Recipe $recipe)
    {
        $this->id = $id;
        $this->ingredient = $ingredient;
        $this->quantity = $quantity;
        $this->unit = $unit;
        $this->recipe = $recipe;
    }

    public static function fromModel(RecipeRowModel $recipeRow, RecipeModel $recipe): static
    {
        return new static(
            $recipeRow->getId(),
            Ingredient::fromModel($recipeRow->getIngredient()),
            $recipeRow->getQuantity(),
            Unit::fromModel($recipeRow->getUnit()),
            Recipe::fromModel($recipe),
        );
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getIngredient(): ?Ingredient
    {
        return $this->ingredient;
    }

    public function setIngredient(?Ingredient $ingredient): static
    {
        $this->ingredient = $ingredient;

        return $this;
    }

    public function getQuantity(): ?float
    {
        return $this->quantity;
    }

    public function setQuantity(float $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getUnit(): ?Unit
    {
        return $this->unit;
    }

    public function setUnit(?Unit $unit): static
    {
        $this->unit = $unit;

        return $this;
    }

    public function getRecipe(): ?Recipe
    {
        return $this->recipe;
    }

    public function setRecipe(?Recipe $recipe): static
    {
        $this->recipe = $recipe;

        return $this;
    }
}
