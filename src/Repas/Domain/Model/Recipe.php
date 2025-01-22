<?php

namespace Repas\Repas\Domain\Model;


use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;
use Repas\User\Domain\Model\User;

class Recipe implements ModelInterface
{

    use ModelTrait;

    private function __construct(
        private string     $id,
        private string     $name,
        private int        $servings,
        private User       $author,
        private RecipeType $type,
        private array      $rows,
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

    public function getServings(): int
    {
        return $this->servings;
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

    public static function load(array $datas): static
    {
        return new self(
            $datas['id'],
            $datas['name'],
            $datas['servings'],
            User::load($datas['author']),
            RecipeType::load($datas['type']),
            $datas['rows']
        );
    }

    public static function create(
        string     $id,
        string     $name,
        int        $servings,
        User       $author,
        RecipeType $recipeType,
        array      $rows,
    ): self {
        return new self($id, $name, $servings, $author, $recipeType, $rows);
    }

}
