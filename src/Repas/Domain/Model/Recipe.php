<?php

namespace Repas\Repas\Domain\Model;


use Repas\User\Domain\Model\User;

final class Recipe
{

    private function __construct(
        private string $id,
        private string $name,
        private int $peopleNumber,
        private User $author,
        private RecipeType $type,
        private array $rows,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPeopleNumber(): int
    {
        return $this->peopleNumber;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function getType(): RecipeType
    {
        return $this->type;
    }

    /**
     * @return array<RecipeRow>
     */
    public function getRows(): array
    {
        return $this->rows;
    }

    public static function load(array $data): self {
        return new self(
            $data['id'],
            $data['name'],
            $data['peopleNumber'],
            User::load($data['author']),
            RecipeType::load($data['type']),
            $data['rows']
        );
    }

    public static function create(
        string $id,
        string $name,
        int $peopleNumber,
        User $author,
        RecipeType $recipeType,
        array $rows,
    ): self {
        return new self($id, $name, $peopleNumber, $author, $recipeType, $rows);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'peopleNumber' => $this->peopleNumber,
            'author' => $this->author->toArray(),
            'type' => $this->type->toArray(),
            'rows' => array_map(fn(RecipeRow $row) => $row->toArray(), $this->rows),
        ];
    }
}
