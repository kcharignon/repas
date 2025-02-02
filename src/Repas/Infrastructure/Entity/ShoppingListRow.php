<?php

namespace Repas\Repas\Infrastructure\Entity;

use Doctrine\ORM\Mapping as ORM;
use Repas\Repas\Domain\Model\ShoppingListRow as ShoppingListRowModel;

#[ORM\Entity]
#[ORM\Table(name: 'shopping_list_row')]
class ShoppingListRow
{
    #[ORM\Id]
    #[ORM\Column(length: 255)]
    private ?string $id = null;

    #[ORM\Column(name: 'shopping_list', nullable: false)]
    private ?string $shoppingListId = null;

    #[ORM\Column(name: 'ingredient', nullable: false)]
    private ?string $ingredientSlug = null;

    #[ORM\Column(name: 'quantity', nullable: false)]
    private ?float $quantity = null;

    #[ORM\Column(name: 'unit', nullable: false)]
    private ?string $unitSlug = null;

    #[ORM\Column]
    private ?bool $checked = null;

    public function __construct(
        string $id,
        string $shoppingListId,
        string $ingredientSlug,
        float  $quantity,
        string $unitSlug,
        bool   $checked,
    ) {
        $this->id = $id;
        $this->shoppingListId = $shoppingListId;
        $this->ingredientSlug = $ingredientSlug;
        $this->quantity = $quantity;
        $this->unitSlug = $unitSlug;
        $this->checked = $checked;
    }

    public static function fromModel(ShoppingListRowModel $rowModel): static
    {
        return new static(
            id: $rowModel->getId(),
            shoppingListId: $rowModel->getShoppingListId(),
            ingredientSlug: $rowModel->getIngredient()->getSlug(),
            quantity: $rowModel->getQuantity(),
            unitSlug: $rowModel->getUnit()->getSlug(),
            checked: $rowModel->isChecked()
        );
    }

    public function updateFromModel(ShoppingListRowModel $rowModel): void
    {
        $this->quantity = $rowModel->getQuantity();
        $this->checked = $rowModel->isChecked();
    }

    public function addQuantity(float $quantity): void
    {
        $this->quantity += $quantity;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(?string $id): ShoppingListRow
    {
        $this->id = $id;
        return $this;
    }

    public function getShoppingListId(): ?string
    {
        return $this->shoppingListId;
    }

    public function setShoppingListId(?string $shoppingListId): ShoppingListRow
    {
        $this->shoppingListId = $shoppingListId;
        return $this;
    }

    public function getIngredientSlug(): ?string
    {
        return $this->ingredientSlug;
    }

    public function setIngredientSlug(?string $ingredientSlug): ShoppingListRow
    {
        $this->ingredientSlug = $ingredientSlug;
        return $this;
    }

    public function getQuantity(): ?float
    {
        return $this->quantity;
    }

    public function setQuantity(?float $quantity): ShoppingListRow
    {
        $this->quantity = $quantity;
        return $this;
    }

    public function getUnitSlug(): ?string
    {
        return $this->unitSlug;
    }

    public function setUnitSlug(?string $unitSlug): ShoppingListRow
    {
        $this->unitSlug = $unitSlug;
        return $this;
    }

    public function isChecked(): ?bool
    {
        return $this->checked;
    }

    public function setChecked(?bool $checked): ShoppingListRow
    {
        $this->checked = $checked;
        return $this;
    }
}
