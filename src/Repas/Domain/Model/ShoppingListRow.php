<?php

namespace Repas\Repas\Domain\Model;


use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;
use Repas\Shared\Domain\Tool\UuidGenerator;

final class ShoppingListRow implements ModelInterface
{
    use ModelTrait;

    public function __construct(
        private string     $id,
        private string     $shoppingListId,
        private string     $ingredientSlug,
        private float      $quantity,
        private string     $unitSlug,
        private bool       $checked,
    ) {
    }

    /**
     * @throws ShoppingListException la quantité n'est pas un nombre fini
     */
    public static function create(
        string $shoppingListId,
        string $ingredientSlug,
        string $unitSlug,
        float  $quantity,
    ): self {
        self::assertFinite($quantity);

        return new self(
            id: UuidGenerator::new(),
            shoppingListId: $shoppingListId,
            ingredientSlug: $ingredientSlug,
            quantity: $quantity,
            unitSlug: $unitSlug,
            checked: false,
        );
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getShoppingListId(): string
    {
        return $this->shoppingListId;
    }

    public function getIngredientSlug(): string
    {
        return $this->ingredientSlug;
    }

    public function getQuantity(): float
    {
        return $this->quantity;
    }

    public function getUnitSlug(): string
    {
        return $this->unitSlug;
    }

    public function isChecked(): bool
    {
        return $this->checked;
    }

    public static function load(array $datas): self
    {
        return new self(
            id: $datas['id'],
            shoppingListId: $datas['shopping_list_id'],
            ingredientSlug: $datas['ingredient_slug'],
            quantity: $datas['quantity'],
            unitSlug: $datas['unit_slug'],
            checked: $datas['checked'],
        );
    }

    /**
     * @throws ShoppingListException le total n'est pas un nombre fini
     */
    public function addQuantity(float $quantity): void
    {
        self::assertFinite($this->quantity + $quantity);
        $this->quantity += $quantity;
    }

    /**
     * @throws ShoppingListException le reste n'est pas un nombre fini
     */
    public function subtractQuantity(float $quantity): void
    {
        self::assertFinite($this->quantity - $quantity);
        $this->quantity -= $quantity;
    }

    private static function assertFinite(float $quantity): void
    {
        if (!is_finite($quantity)) {
            throw ShoppingListException::invalidQuantity();
        }
    }

    public function tick(): void
    {
        $this->checked = true;
    }

    public function uncheck(): void
    {
        $this->checked = false;
    }
}
