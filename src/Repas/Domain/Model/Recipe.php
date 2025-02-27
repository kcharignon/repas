<?php

namespace Repas\Repas\Domain\Model;


use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Domain\Model\User;
use Repas\Repas\Domain\Model\RecipeType as Type;

final class Recipe implements ModelInterface
{

    use ModelTrait;

    /**
     * @param Tab<RecipeRow> $rows
     */
    private function __construct(
        private string  $id,
        private string  $name,
        private int     $serving,
        private User    $author,
        private Type    $type,
        private Tab     $rows,
        private ?string $originalId,
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

    public function getType(): Type
    {
        return $this->type;
    }

    /**
     * @return Tab<RecipeRow>
     */
    public function getRows(): Tab
    {
        return $this->rows;
    }

    public function setName(string $name): Recipe
    {
        $this->name = $name;
        return $this;
    }

    public function setServing(int $serving): Recipe
    {
        $this->serving = $serving;
        return $this;
    }

    public function setType(Type $type): Recipe
    {
        $this->type = $type;
        return $this;
    }

    public function setRows(Tab $rows): Recipe
    {
        $this->rows = $rows;
        return $this;
    }

    public function getOriginalId(): ?string
    {
        return $this->originalId;
    }

    public function setOriginalId(?string $originalId): Recipe
    {
        $this->originalId = $originalId;
        return $this;
    }

    public static function load(array $datas): self
    {
        return new self(
            id: $datas['id'],
            name: $datas['name'],
            serving: $datas['serving'],
            author: $datas['author'],
            type: $datas['type'],
            rows: $datas['rows'],
            originalId: $datas['original_id'],
        );
    }

    public static function create(
        string  $id,
        string  $name,
        int     $servings,
        User    $author,
        Type    $recipeType,
        Tab     $rows,
        ?string $originalId,
    ): self {
        return new self($id, $name, $servings, $author, $recipeType, $rows, $originalId);
    }

    public static function copyFromOriginal(
        string $id,
        Recipe $original,
        User   $author,
    ): self {
        return new self(
            id: $id,
            name: $original->getName(),
            serving: $original->getServing(),
            author: $author,
            type: $original->getType(),
            rows: $original->getRows()->map(fn(RecipeRow $row) => RecipeRow::copyFromOriginal(
                id: UuidGenerator::new(),
                originalRow: $row,
                recipeId: $id,
                author: $author,
            )),
            originalId: $original->getId()
        );
    }

    public function isType(Type $type): bool
    {
        return $this->type->isEqual($type);
    }

    /**
     * @return Tab<Department>
     */
    public function departmentPresent(): Tab
    {
        $res = Tab::newEmptyTyped(Department::class);
        /** @var RecipeRow $row */
        foreach ($this->rows as $row) {
            $department = $row->getDepartment();
            if (!isset($res[$department->getSlug()])) {
                $res[$department->getSlug()] = $department;
            }
        }
        return $res;
    }

    /**
     * On retourne les lignes d'ingrédient de recettes avec leur
     * quantité * (nombre de personnes voulu / nombre de personnes dans la recette).
     *
     * @return Tab<RecipeRow>
     */
    public function getRowForServing(int $serving): Tab
    {
        return $this->rows->map(fn(RecipeRow $row) => $row->multiplyQuantityBy($serving/$this->serving));
    }

    public function update(string $name, RecipeType $type, int $serving): void
    {
        $this->name = $name;
        $this->type = $type;
        $this->serving = $serving;
    }

    public function addRow(Ingredient $ingredient, Unit $unit, float $quantity): void
    {
        $row = RecipeRow::create(
            id: UuidGenerator::new(),
            recipeId: $this->id,
            ingredient: $ingredient,
            quantity: $quantity,
            unit: $unit
        );
        $this->rows[] = $row;
    }

    public function isOriginal(): bool
    {
        return $this->originalId === null;
    }
}
