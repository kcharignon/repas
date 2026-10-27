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

    /** Nombre de personnes d'une recette : de 1 à 100, comme celui d'un repas (`Meal`) et celui d'un compte. */
    public const int MIN_SERVING = 1;
    public const int MAX_SERVING = 100;

    /** Lignes d'une recette : une recette en a une dizaine, jamais cent. */
    public const int MAX_ROWS = 100;

    /** Recettes qu'un auteur peut écrire (la copie de celle d'un autre n'est pas comptée). */
    public const int MAX_PER_AUTHOR = 1000;

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

    /**
     * @throws RecipeException le nombre de personnes est hors de 1 à 100
     */
    public function setServing(int $serving): Recipe
    {
        self::assertServing($serving);
        $this->serving = $serving;
        return $this;
    }

    public function setType(Type $type): Recipe
    {
        $this->type = $type;
        return $this;
    }

    /**
     * @param Tab<RecipeRow> $rows
     * @throws RecipeException il y a plus de lignes que `MAX_ROWS`
     */
    public function setRows(Tab $rows): Recipe
    {
        self::assertRowCount($rows->count());
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

    /**
     * @param Tab<RecipeRow> $rows
     * @throws RecipeException le nombre de personnes est hors de 1 à 100, ou il y a plus de lignes que `MAX_ROWS`
     * @throws IngredientException un ingrédient n'est pas à l'auteur
     */
    public static function create(
        string  $id,
        string  $name,
        int     $servings,
        string  $authorId,
        Type    $recipeType,
        Tab     $rows,
        ?string $originalId,
    ): self {
        self::assertServing($servings);
        self::assertRowCount($rows->count());

        $recipe = new self($id, $name, $servings, $authorId, $recipeType, $rows, $originalId);
        foreach ($rows as $row) {
            $recipe->assertIngredientIsAvailable($row->getIngredient());
        }

        return $recipe;
    }

    /**
     * La copie d'une recette pour `$authorId`. Elle ne crée ni ne cherche aucun ingrédient : le cas d'usage a retenu, pour chaque
     * ingrédient de l'original, celui que le copieur utilisera (ADR 0028 décision 8), et le donne ici par slug d'origine.
     *
     * @param array<string, Ingredient> $ingredients l'ingrédient du copieur par slug d'ingrédient de l'original
     * @param callable(): string $newRowId l'identifiant d'une nouvelle ligne
     * @throws IngredientException un ingrédient retenu n'est pas disponible pour le copieur (celui d'un autre) ou n'a pas été retenu
     */
    public static function copyFromOriginal(
        string $id,
        Recipe $original,
        string $authorId,
        array $ingredients,
        callable $newRowId,
    ): self {
        $copy = new self(
            id: $id,
            name: $original->getName(),
            serving: $original->getServing(),
            authorId: $authorId,
            type: $original->getType(),
            rows: $original->getRows()->map(fn(RecipeRow $row) => RecipeRow::copyFromOriginal(
                id: $newRowId(),
                originalRow: $row,
                recipeId: $id,
                ingredient: $ingredients[$row->getIngredient()->getSlug()] ?? throw IngredientException::notFound($row->getIngredient()->getSlug()),
            )),
            originalId: $original->getId()
        );
        foreach ($copy->rows as $row) {
            $copy->assertIngredientIsAvailable($row->getIngredient());
        }

        return $copy;
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

    /**
     * @throws RecipeException le nombre de personnes est hors de 1 à 100
     */
    public function update(string $name, RecipeType $type, int $serving): void
    {
        self::assertServing($serving);

        $this->name = $name;
        $this->type = $type;
        $this->serving = $serving;
    }

    /**
     * @throws RecipeException la recette a déjà `MAX_ROWS` lignes, ou la quantité n'est pas valide
     * @throws IngredientException l'ingrédient n'est pas à l'auteur
     */
    public function addRow(Ingredient $ingredient, Unit $unit, float $quantity): void
    {
        self::assertRowCount($this->rows->count() + 1);
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

    private static function assertServing(int $serving): void
    {
        if ($serving < self::MIN_SERVING || $serving > self::MAX_SERVING) {
            throw RecipeException::invalidServing();
        }
    }

    private static function assertRowCount(int $count): void
    {
        if ($count > self::MAX_ROWS) {
            throw RecipeException::tooManyRows();
        }
    }

    /**
     * Un ingrédient n'est utilisable que par son propriétaire (ADR 0028, amendement : plus d'ingrédient commun) : l'ingrédient d'un
     * autre utilisateur est traité comme inconnu (on ne révèle pas son existence).
     *
     * @throws IngredientException
     */
    private function assertIngredientIsAvailable(Ingredient $ingredient): void
    {
        if (!$ingredient->isOwnedBy($this->authorId)) {
            throw IngredientException::notFound($ingredient->getSlug());
        }
    }

    public function isOriginal(): bool
    {
        return $this->originalId === null;
    }
}
