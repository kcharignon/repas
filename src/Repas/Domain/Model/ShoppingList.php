<?php

namespace Repas\Repas\Domain\Model;


use DateTimeImmutable;
use Repas\Shared\Domain\Tool\Tab;

class ShoppingList
{

    /**
     * @param Tab<Recipe> $recipes
     */
    private function __construct(
        private string $id,
        private DateTimeImmutable $date,
        private bool $locked,
        private Tab $recipes,
    ) {
    }

    public static function create(
        string $id,
        DateTimeImmutable $date,
        bool $locked,
        Tab $recipes,
    ): ShoppingList {
        return new ShoppingList(
            id: $id,
            date: $date,
            locked: $locked,
            recipes: $recipes
        );
    }

    public static function load(array $data): ShoppingList
    {
        return new static(
            id: $data['id'],
            date: DateTimeImmutable::createFromFormat(DATE_ATOM, $data['date']),
            locked: $data['locked'],
            recipes: new Tab($data['recipes'])->map(fn (array $recipe) => Recipe::load($recipe)),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date->format(DATE_ATOM),
            'locked' => $this->locked,
            'recipe' => $this->recipes->map(fn(Recipe $recipe) => $recipe->toArray())->all(),
        ];
    }
}
