<?php

namespace Repas\Repas\Infrastructure\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingList as ShoppingListModel;

#[ORM\Entity(repositoryClass: ShoppingListRepository::class)]
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

    #[ORM\Column]
    private ?bool $locked = null;

    public function __construct(
        string            $id,
        string            $ownerId,
        DateTimeImmutable $createdAt,
        bool              $locked,
    ) {
        $this->id = $id;
        $this->ownerId = $ownerId;
        $this->createdAt = $createdAt;
        $this->locked = $locked;
    }

    public static function fromModel(ShoppingListModel $shoppingListModel): static
    {
        return new static (
            id: $shoppingListModel->getId(),
            ownerId: $shoppingListModel->getOwner()->getId(),
            createdAt: $shoppingListModel->getCreatedAt(),
            locked: $shoppingListModel->isLocked(),
        );
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

    public function isLocked(): ?bool
    {
        return $this->locked;
    }

    public function setLocked(bool $locked): static
    {
        $this->locked = $locked;

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
}
