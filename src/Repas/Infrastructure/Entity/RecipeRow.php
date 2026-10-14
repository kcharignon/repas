<?php

namespace Repas\Repas\Infrastructure\Entity;

use Doctrine\ORM\Mapping as ORM;
use Repas\Repas\Domain\Model\Recipe as RecipeModel;
use Repas\Repas\Domain\Model\RecipeRow as RecipeRowModel;

#[ORM\Entity]
#[ORM\Table(name: 'recipe_row')]
class RecipeRow
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private ?string $id = null;

    #[ORM\Column(name: "ingredient", nullable: false)]
    private ?string $ingredientSlug = null;

    #[ORM\Column]
    private ?float $quantity = null;

    #[ORM\Column(name: "unit", nullable: false)]
    private ?string $unitSlug = null;

    #[ORM\Column(name: 'recipe', nullable: false)]
    private ?string $recipeId = null;

    // Rang de la ligne dans la recette : l'ordre de saisie est conservé à la lecture
    #[ORM\Column(name: 'position', type: 'integer', nullable: false)]
    private int $position = 0;

    public function __construct(
        ?string $id,
        ?string $ingredientSlug,
        ?float  $quantity,
        ?string $unitSlug,
        ?string $recipeId,
        int     $position = 0,
    ) {
        $this->id = $id;
        $this->ingredientSlug = $ingredientSlug;
        $this->quantity = $quantity;
        $this->unitSlug = $unitSlug;
        $this->recipeId = $recipeId;
        $this->position = $position;
    }

    public static function fromModel(RecipeRowModel $recipeRow, int $position = 0): static
    {
        return new static(
            $recipeRow->getId(),
            $recipeRow->getIngredient()->getSlug(),
            $recipeRow->getQuantity(),
            $recipeRow->getUnit()->getSlug(),
            $recipeRow->getRecipeId(),
            $position,
        );
    }

    public function updateFromModel(RecipeRowModel $recipeRowModel, int $position = 0): void
    {
        $this->ingredientSlug = $recipeRowModel->getIngredient()->getSlug();
        $this->quantity = $recipeRowModel->getQuantity();
        $this->unitSlug = $recipeRowModel->getUnit()->getSlug();
        $this->position = $position;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getIngredientSlug(): ?string
    {
        return $this->ingredientSlug;
    }

    public function setIngredientSlug(?string $ingredientSlug): static
    {
        $this->ingredientSlug = $ingredientSlug;

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

    public function getUnitSlug(): ?string
    {
        return $this->unitSlug;
    }

    public function setUnitSlug(?string $unitSlug): static
    {
        $this->unitSlug = $unitSlug;

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
}
