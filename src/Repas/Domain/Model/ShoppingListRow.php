<?php

namespace Repas\Repas\Domain\Model;


use DateTimeImmutable;
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
        // Qui a coché la ligne et quand (ADR 0041) : posés avec la coche, effacés avec elle ; vides pour une ligne cochée avant l'ADR 0041
        private ?RowCheckSource $checkedBy = null,
        private ?DateTimeImmutable $checkedAt = null,
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

    public function getCheckedBy(): ?RowCheckSource
    {
        return $this->checkedBy;
    }

    public function getCheckedAt(): ?DateTimeImmutable
    {
        return $this->checkedAt;
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
            checkedBy: $datas['checked_by'] ?? null,
            checkedAt: $datas['checked_at'] ?? null,
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

    /**
     * La personne coche la ligne à la main (ADR 0041) : l'origine et l'instant sont gardés. Une ligne déjà cochée par le drive garde son origine.
     */
    public function tick(DateTimeImmutable $at): void
    {
        if (!$this->checked || $this->checkedBy === null) {
            $this->checkedBy = RowCheckSource::MANUAL;
            $this->checkedAt = $at;
        }
        $this->checked = true;
    }

    public function uncheck(): void
    {
        $this->checked = false;
        $this->checkedBy = null;
        $this->checkedAt = null;
    }
}
