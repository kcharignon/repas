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
        private int        $serving,
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

    public function getServing(): int
    {
        return $this->serving;
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
            id: $datas['id'],
            name: $datas['name'],
            serving: $datas['serving'],
            author: static::loadModel($datas['author'], User::class),
            type: static::loadModel($datas['type'], RecipeType::class),
            rows: array_map(fn($recipeRow) => static::loadModel($recipeRow, RecipeRow::class) , $datas['rows'])
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
