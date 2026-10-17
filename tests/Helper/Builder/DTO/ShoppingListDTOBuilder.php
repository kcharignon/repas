<?php

namespace Repas\Tests\Helper\Builder\DTO;


use Repas\Repas\Application\Query\ListShoppingLists\ShoppingListDTO;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Tests\Helper\Builder\Builder;

class ShoppingListDTOBuilder implements Builder
{
    private string $id;
    private string $displayName;
    private ShoppingListStatus $status;
    private string $createdAt;
    private ?string $sentAt = null;

    private function initialize(): void
    {
        $this->id ??= 'shopping-list-1';
        $this->displayName ??= '12 octobre 2026';
        $this->status ??= ShoppingListStatus::ACTIVE;
        $this->createdAt ??= '2026-10-12T10:00:00+02:00';
    }

    public function withId(string $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function withDisplayName(string $displayName): self
    {
        $this->displayName = $displayName;
        return $this;
    }

    public function withStatus(ShoppingListStatus $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function withCreatedAt(string $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function withSentAt(?string $sentAt): self
    {
        $this->sentAt = $sentAt;
        return $this;
    }

    public function build(): ShoppingListDTO
    {
        $this->initialize();
        return new ShoppingListDTO(
            id: $this->id,
            displayName: $this->displayName,
            status: $this->status->value,
            isActive: $this->status === ShoppingListStatus::ACTIVE,
            isPaused: $this->status === ShoppingListStatus::PAUSED,
            isCompleted: $this->status === ShoppingListStatus::COMPLETED,
            isSent: $this->status === ShoppingListStatus::SENT,
            sentAt: $this->sentAt ?? ($this->status === ShoppingListStatus::SENT ? '2026-10-18T10:00:00+02:00' : null),
            createdAt: $this->createdAt,
        );
    }
}
