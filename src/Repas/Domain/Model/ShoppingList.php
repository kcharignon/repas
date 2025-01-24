<?php

namespace Repas\Repas\Domain\Model;


use DateTimeImmutable;
use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;
use Repas\Shared\Domain\Tool\Tab;
use Repas\User\Domain\Model\User;

class ShoppingList implements ModelInterface
{
    use ModelTrait;

    /**
     * @param Tab<Recipe> $recipes
     */
    private function __construct(
        private string            $id,
        private User              $owner,
        private DateTimeImmutable $createdAt,
        private bool              $locked,
        private Tab               $recipes,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    public function getRecipes(): Tab
    {
        return $this->recipes;
    }

    public static function create(
        string            $id,
        User              $owner,
        DateTimeImmutable $createdAt,
        bool              $locked,
        Tab               $recipes,
    ): ShoppingList {
        return new ShoppingList(
            id: $id,
            owner: $owner,
            createdAt: $createdAt,
            locked: $locked,
            recipes: $recipes
        );
    }

    public static function load(array $datas): static
    {
        return new static(
            id: $datas['id'],
            owner: static::loadModel($datas['owner'], User::class),
            createdAt: static::loadDateTime($datas['created_at'], DateTimeImmutable::class),
            locked: $datas['locked'],
            recipes: Tab::fromArray($datas['recipes'])
                ->map(fn($recipe) => static::loadModel($recipe, Meal::class))
            ,
        );
    }
}
