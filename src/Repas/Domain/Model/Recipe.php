<?php

namespace Repas\Repas\Domain\Model;


use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Domain\Tool\UuidGenerator;
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
        private string  $authorId,
        private Type    $type,
        private Tab     $rows,
        private ?string $originalId,
        private int     $version = 1,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Version de la recette (ETag, ADR 0016) : augmente à chaque enregistrement.
     */
    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * Refuse la modification si le client a lu une autre version que celle d'aujourd'hui (`If-Match`). Sans version
     * attendue, la modification passe : le contrôle est facultatif.
     *
     * @throws RecipeException
     */
    public function assertVersion(?int $expectedVersion): void
    {
        if ($expectedVersion !== null && $expectedVersion !== $this->version) {
            throw RecipeException::outdated($this->id);
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getServing(): int
    {
        return $this->serving;
    }

    /**
     * Identifiant de l'utilisateur qui a écrit la recette (ADR 0013 règle 8 : un agrégat référence un utilisateur par son
     * identifiant).
     */
    public function getAuthorId(): string
    {
        return $this->authorId;
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
            authorId: $datas['author_id'],
            type: $datas['type'],
            rows: $datas['rows'],
            originalId: $datas['original_id'],
            version: $datas['version'] ?? 1,
        );
    }

    public static function create(
        string  $id,
        string  $name,
        int     $servings,
        string  $authorId,
        Type    $recipeType,
        Tab     $rows,
        ?string $originalId,
    ): self {
        $recipe = new self($id, $name, $servings, $authorId, $recipeType, $rows, $originalId);
        foreach ($rows as $row) {
            $recipe->assertIngredientIsAvailable($row->getIngredient());
        }

        return $recipe;
    }

    public static function copyFromOriginal(
        string $id,
        Recipe $original,
        string $authorId,
    ): self {
        return new self(
            id: $id,
            name: $original->getName(),
            serving: $original->getServing(),
            authorId: $authorId,
            type: $original->getType(),
            rows: $original->getRows()->map(fn(RecipeRow $row) => RecipeRow::copyFromOriginal(
                id: UuidGenerator::new(),
                originalRow: $row,
                recipeId: $id,
                authorId: $authorId,
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
        $this->assertIngredientIsAvailable($ingredient);

        $row = RecipeRow::create(
            id: UuidGenerator::new(),
            recipeId: $this->id,
            ingredient: $ingredient,
            quantity: $quantity,
            unit: $unit
        );
        $this->rows[] = $row;
    }

    /**
     * Un ingrédient commun est utilisable par tous, un ingrédient personnalisé seulement par son créateur : l'ingrédient
     * d'un autre utilisateur est traité comme inconnu (on ne révèle pas son existence).
     *
     * @throws IngredientException
     */
    private function assertIngredientIsAvailable(Ingredient $ingredient): void
    {
        $creatorId = $ingredient->getCreatorId();
        if ($creatorId !== null && $creatorId !== $this->authorId) {
            throw IngredientException::notFound($ingredient->getSlug());
        }
    }

    public function isOriginal(): bool
    {
        return $this->originalId === null;
    }
}
