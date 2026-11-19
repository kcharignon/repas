<?php

namespace Repas\Repas\Infrastructure\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Repas\Repas\Domain\Model\DriveLineOutcome;
use Repas\Repas\Domain\Model\RowCheckSource;
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

    #[ORM\Column(name: 'checked', nullable: false)]
    private ?bool $checked = null;

    // Qui a coché la ligne et quand (ADR 0041) ; vides tant qu'elle n'est pas cochée et pour une ligne cochée avant l'ADR 0041. Écrits par le modèle
    #[ORM\Column(name: 'checked_by', type: 'string', length: 16, nullable: true, enumType: RowCheckSource::class)]
    private ?RowCheckSource $checkedBy = null;

    #[ORM\Column(name: 'checked_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $checkedAt = null;

    // Ce que le DERNIER passage au drive a fait de la ligne (ADR 0041) : écrit par une seule instruction SQL (`recordDrivePassage`), jamais par le modèle,
    // donc jamais effacé par l'enregistrement d'une liste ; vide tant que la ligne n'a pas été au drive
    #[ORM\Column(name: 'drive_outcome', type: 'string', length: 16, nullable: true, enumType: DriveLineOutcome::class)]
    private ?DriveLineOutcome $driveOutcome = null;

    #[ORM\Column(name: 'drive_reason', type: 'string', length: 40, nullable: true)]
    private ?string $driveReason = null;

    #[ORM\Column(name: 'drive_attempted_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $driveAttemptedAt = null;

    public function __construct(
        string $id,
        string $shoppingListId,
        string $ingredientSlug,
        float  $quantity,
        string $unitSlug,
        bool   $checked,
        ?RowCheckSource $checkedBy = null,
        ?DateTimeImmutable $checkedAt = null,
        ?DriveLineOutcome $driveOutcome = null,
        ?string $driveReason = null,
        ?DateTimeImmutable $driveAttemptedAt = null,
    ) {
        $this->id = $id;
        $this->shoppingListId = $shoppingListId;
        $this->ingredientSlug = $ingredientSlug;
        $this->quantity = $quantity;
        $this->unitSlug = $unitSlug;
        $this->checked = $checked;
        $this->checkedBy = $checkedBy;
        $this->checkedAt = $checkedAt;
        $this->driveOutcome = $driveOutcome;
        $this->driveReason = $driveReason;
        $this->driveAttemptedAt = $driveAttemptedAt;
    }

    public static function fromModel(ShoppingListRowModel $rowModel): static
    {
        return new static(
            id: $rowModel->getId(),
            shoppingListId: $rowModel->getShoppingListId(),
            ingredientSlug: $rowModel->getIngredientSlug(),
            quantity: $rowModel->getQuantity(),
            unitSlug: $rowModel->getUnitSlug(),
            checked: $rowModel->isChecked(),
            checkedBy: $rowModel->getCheckedBy(),
            checkedAt: $rowModel->getCheckedAt(),
        );
    }

    public function updateFromModel(ShoppingListRowModel $rowModel): void
    {
        $this->quantity = $rowModel->getQuantity();
        $this->checked = $rowModel->isChecked();
        $this->checkedBy = $rowModel->getCheckedBy();
        $this->checkedAt = $rowModel->getCheckedAt();
    }

    public function getCheckedBy(): ?RowCheckSource
    {
        return $this->checkedBy;
    }

    public function getCheckedAt(): ?DateTimeImmutable
    {
        return $this->checkedAt;
    }

    public function getDriveOutcome(): ?DriveLineOutcome
    {
        return $this->driveOutcome;
    }

    public function getDriveReason(): ?string
    {
        return $this->driveReason;
    }

    public function getDriveAttemptedAt(): ?DateTimeImmutable
    {
        return $this->driveAttemptedAt;
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
