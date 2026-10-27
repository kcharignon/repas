<?php

namespace Repas\Repas\Domain\Model;


use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;
use Repas\Shared\Domain\Tool\UuidGenerator;

final class ShoppingListIngredient implements ModelInterface
{
    use ModelTrait;


    public function __construct(
        private string                $id,
        private string                $shoppingListId,
        private string                $ingredientSlug,
        private string                $unitSlug,
        private float                 $quantity,
    ) {
    }

    /**
     * @throws ShoppingListException la quantité n'est pas un nombre fini
     */
    public static function create(
        string $shoppingListId,
        string $ingredientSlug,
        string $unitSlug,
        float  $quantity
    ): self {
        self::assertFinite($quantity);

        return new self(
            id: UuidGenerator::new(),
            shoppingListId: $shoppingListId,
            ingredientSlug: $ingredientSlug,
            unitSlug: $unitSlug,
            quantity: $quantity,
        );
    }

    public static function load(array $datas): self
    {
        return new self(
            id : $datas['id'],
            shoppingListId : $datas['shopping_list_id'],
            ingredientSlug : $datas['ingredient_slug'],
            unitSlug : $datas['unit_slug'],
            quantity : $datas['quantity'],
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

    public function getUnitSlug(): string
    {
        return $this->unitSlug;
    }

    public function getQuantity(): float
    {
        return $this->quantity;
    }

    /**
     * @throws ShoppingListException le total n'est pas un nombre fini
     */
    public function addQuantity(float $getQuantity): void
    {
        self::assertFinite($this->quantity + $getQuantity);
        $this->quantity += $getQuantity;
    }

    /**
     * @throws ShoppingListException le reste n'est pas un nombre fini
     */
    public function subtractQuantity(float $getQuantity): void
    {
        self::assertFinite($this->quantity - $getQuantity);
        $this->quantity -= $getQuantity;
    }

    private static function assertFinite(float $quantity): void
    {
        if (!is_finite($quantity)) {
            throw ShoppingListException::invalidQuantity();
        }
    }

    public function hasIngredient(string $ingredientSlug): bool
    {
        return $this->ingredientSlug === $ingredientSlug;
    }
}
