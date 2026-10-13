<?php

namespace Repas\Tests\Helper\Builder\DTO;


use Repas\Repas\Application\Query\GetShoppingList\ShoppingListDepartmentDTO;
use Repas\Repas\Application\Query\GetShoppingList\ShoppingListDetailDTO;
use Repas\Repas\Application\Query\GetShoppingList\ShoppingListRecipeTypeDTO;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Tests\Helper\Builder\Builder;

class ShoppingListDetailDTOBuilder implements Builder
{
    private string $id = 'shopping-list-1';
    private ?string $name = null;
    private string $dateLabel = '12 octobre 2026';
    private ShoppingListStatus $status = ShoppingListStatus::ACTIVE;
    /** @var list<ShoppingListDepartmentDTO> */
    private array $departments = [];
    /** @var list<ShoppingListRecipeTypeDTO> */
    private array $recipeTypes = [];

    public function withId(string $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function withName(?string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function withDateLabel(string $dateLabel): self
    {
        $this->dateLabel = $dateLabel;
        return $this;
    }

    public function withStatus(ShoppingListStatus $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function withDepartment(ShoppingListDepartmentDTO $department): self
    {
        $this->departments[] = $department;
        return $this;
    }

    public function withRecipeType(ShoppingListRecipeTypeDTO $recipeType): self
    {
        $this->recipeTypes[] = $recipeType;
        return $this;
    }

    public function build(): ShoppingListDetailDTO
    {
        return new ShoppingListDetailDTO(
            id: $this->id,
            name: $this->name,
            displayName: $this->name ?? 'Liste du ' . $this->dateLabel,
            dateLabel: $this->dateLabel,
            createdAt: '2026-10-12T10:00:00+02:00',
            status: $this->status->value,
            isActive: $this->status === ShoppingListStatus::ACTIVE,
            isPaused: $this->status === ShoppingListStatus::PAUSED,
            isCompleted: $this->status === ShoppingListStatus::COMPLETED,
            rowCount: array_sum(array_map(static fn(ShoppingListDepartmentDTO $department): int => count($department->rows), $this->departments)),
            recipeCount: array_sum(array_map(static fn(ShoppingListRecipeTypeDTO $type): int => count($type->meals), $this->recipeTypes)),
            departments: $this->departments,
            recipeTypes: $this->recipeTypes,
        );
    }
}
