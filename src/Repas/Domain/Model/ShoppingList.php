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
        private string $id,
        private User $owner,
        private DateTimeImmutable $date,
        private bool $locked,
        private Tab $recipes,
    ) {
    }

    public static function create(
        string $id,
        User $owner,
        DateTimeImmutable $date,
        bool $locked,
        Tab $recipes,
    ): ShoppingList {
        return new ShoppingList(
            id: $id,
            owner: $owner,
            date: $date,
            locked: $locked,
            recipes: $recipes
        );
    }

    public static function load(array $datas): static
    {
        return new static(
            id: $datas['id'],
            owner: User::load($datas['owner']),
            date: DateTimeImmutable::createFromFormat(DATE_ATOM, $datas['date']),
            locked: $datas['locked'],
            recipes: new Tab($datas['recipes'])->map(fn (array $recipe) => RecipeInShoppingList::load($recipe)),
        );
    }
}
