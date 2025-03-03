<?php

namespace Repas\Repas\Infrastructure\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Repas\Repas\Domain\Model\ShoppingList as ShoppingListModel;
use Repas\Repas\Domain\Model\ShoppingListStatus as Status;

#[ORM\Entity]
#[ORM\Table(name: 'shopping_list')]
class ShoppingList
{
    #[ORM\Id]
    #[ORM\Column(length: 255)]
    private ?string $id = null;

    #[ORM\Column(name: 'owner', nullable: false)]
    private ?string $ownerId = null;

    #[ORM\Column(name: 'created_at')]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'string', enumType: Status::class)]
    private ?Status $status = null;

    #[ORM\Column(name: 'name', nullable: true)]
    private ?string $name = null;

    public function __construct(
        string            $id,
        string            $ownerId,
        DateTimeImmutable $createdAt,
        Status            $status,
        ?string            $name
    ) {
        $this->id = $id;
        $this->ownerId = $ownerId;
        $this->createdAt = $createdAt;
        $this->status = $status;
        $this->name = $name;
    }

    public static function fromModel(ShoppingListModel $shoppingListModel): static
    {
        return new static (
            id: $shoppingListModel->getId(),
            ownerId: $shoppingListModel->getOwner()->getId(),
            createdAt: $shoppingListModel->getCreatedAt(),
            status: $shoppingListModel->getStatus(),
            name: $shoppingListModel->getName(),
        );
    }

    public function updateFromModel(ShoppingListModel $shoppingListModel): void
    {
        $this->status = $shoppingListModel->getStatus();
        $this->name = $shoppingListModel->getName();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getStatus(): ?Status
    {
        return $this->status;
    }

    public function setStatus(?Status $status): ShoppingList
    {
        $this->status = $status;
        return $this;
    }

    public function getOwnerId(): ?string
    {
        return $this->ownerId;
    }

    public function setOwnerId(?string $ownerId): static
    {
        $this->ownerId = $ownerId;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): ShoppingList
    {
        $this->name = $name;
        return $this;
    }
}
