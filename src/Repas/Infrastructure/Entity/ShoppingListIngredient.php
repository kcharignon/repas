<?php

namespace Repas\Repas\Infrastructure\Entity;

use Doctrine\ORM\Mapping as ORM;
use Repas\Repas\Domain\Model\ShoppingListIngredient as ShoppingListIngredientModel;

#[ORM\Entity]
#[ORM\Table(name: 'shopping_list_ingredient')]
class ShoppingListIngredient
{
    #[ORM\Id]
    #[ORM\Column]
    private ?string $id = null;

    #[ORM\Column(length: 255)]
    private ?string $shoppingListId = null;

    #[ORM\Column(length: 255)]
    private ?string $ingredientSlug = null;

    #[ORM\Column(length: 255)]
    private ?string $unitSlug = null;

    #[ORM\Column]
    private ?float $quantity = null;

    public function __construct(
        ?string $id,
        ?string $shoppingListId,
        ?string $ingredientSlug,
        ?string $unitSlug,
        ?float $quantity
    ) {
        $this->id = $id;
        $this->shoppingListId = $shoppingListId;
        $this->ingredientSlug = $ingredientSlug;
        $this->unitSlug = $unitSlug;
        $this->quantity = $quantity;
    }

    public static function fromModel(ShoppingListIngredientModel $model): static
    {
        return new static(
            id: $model->getId(),
            shoppingListId: $model->getShoppingListId(),
            ingredientSlug: $model->getIngredient()->getSlug(),
            unitSlug: $model->getUnit()->getSlug(),
            quantity: $model->getQuantity(),
        );
    }

    public function updateFromModel(ShoppingListIngredientModel $model): void
    {
        $this->quantity = $model->getQuantity();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getShoppingListId(): ?string
    {
        return $this->shoppingListId;
    }

    public function setShoppingListId(string $shoppingListId): static
    {
        $this->shoppingListId = $shoppingListId;

        return $this;
    }

    public function getIngredientSlug(): ?string
    {
        return $this->ingredientSlug;
    }

    public function setIngredientSlug(string $ingredientSlug): static
    {
        $this->ingredientSlug = $ingredientSlug;

        return $this;
    }

    public function getUnitSlug(): ?string
    {
        return $this->unitSlug;
    }

    public function setUnitSlug(string $unitSlug): static
    {
        $this->unitSlug = $unitSlug;

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
}
