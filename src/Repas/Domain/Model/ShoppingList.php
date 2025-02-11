<?php

namespace Repas\Repas\Domain\Model;


use DateTimeImmutable;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;
use Repas\Shared\Domain\Tool\Tab;
use Repas\User\Domain\Model\User;
use Repas\Repas\Domain\Model\ShoppingListStatus as Status;
use Repas\Repas\Domain\Model\ShoppingListRow as Row;

final class ShoppingList implements ModelInterface
{
    use ModelTrait;

    /** @var Tab<RecipeType>|null  */
    private ?Tab $recipeTypes = null;

    /**
     * @param Tab<Meal> $meals
     * @param Tab<ShoppingListIngredient> $ingredients
     * @param Tab<Row> $rows
     */
    private function __construct(
        private string            $id,
        private User              $owner,
        private DateTimeImmutable $createdAt,
        private Status            $status,
        private Tab               $meals,
        private Tab               $ingredients,
        private Tab               $rows,
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

    /**
     * @return Tab<Meal>
     */
    public function getMeals(): Tab
    {
        return $this->meals;
    }

    /**
     * @return Tab<ShoppingListIngredient>
     */
    public function getIngredients(): Tab
    {
        return $this->ingredients;
    }

    public function getRows(): Tab
    {
        return $this->rows->usort(fn(Row $a, Row $b) => $a->getIngredient()->getSlug() <=> $b->getIngredient()->getSlug());
    }

    public static function create(
        string            $id,
        User              $owner,
        DateTimeImmutable $createdAt,
    ): ShoppingList {
        return new ShoppingList(
            id: $id,
            owner: $owner,
            createdAt: $createdAt,
            status: Status::ACTIVE,
            meals: Tab::newEmptyTyped(Meal::class),
            ingredients: Tab::newEmptyTyped(ShoppingListIngredient::class),
            rows: Tab::newEmptyTyped(Row::class),
        );
    }

    public static function load(array $datas): self
    {
        return new self(
            id: $datas['id'],
            owner: $datas['owner'],
            createdAt: $datas['created_at'],
            status: $datas['status'],
            meals: $datas['meals'],
            ingredients: $datas['ingredients'],
            rows: $datas['rows'],
        );
    }

    /**
     * @return Tab<RecipeType>
     */
    public function recipeTypePresent(): Tab
    {
        if (null === $this->recipeTypes) {
            $this->recipeTypes = Tab::newEmptyTyped(RecipeType::class);
            foreach ($this->meals as $meal) {
                $mealRecipeType = $meal->getRecipeType();
                if (!isset($this->recipeTypes[$mealRecipeType->getSlug()])) {
                    $this->recipeTypes[$mealRecipeType->getSlug()] = $mealRecipeType;
                }
            }
        }

        // Tri des types par ordre de séquence
        return $this->recipeTypes
            ->usort(fn(RecipeType $type1, RecipeType $type2) =>  $type1->getOrder() <=> $type2->getOrder());
    }

    /**
     * @param RecipeType $recipeType
     * @return Tab<Recipe>
     */
    public function getRecipesByType(RecipeType $recipeType): Tab
    {
        $res = Tab::newEmptyTyped(Recipe::class);

        foreach ($this->meals as $meal) {
            if ($meal->getRecipeType()->isEqual($recipeType)) {
                $res[] = $meal->getRecipe();
            }
        }

        return $res;
    }

    /**
     * @param RecipeType $recipeType
     * @return Tab<Meal>
     */
    public function getMealByType(RecipeType $recipeType): Tab
    {
        return $this->meals->filter(fn(Meal $meal) => $meal->getRecipeType()->isEqual($recipeType));
    }

    /**
     * @return Tab<Department>
     */
    public function getDepartments(): Tab
    {
        $res = Tab::newEmptyTyped(Department::class);
        foreach ($this->rows as $row) {
            $department = $row->getDepartment();
            $res[$department->getSlug()] = $department;
        }
        return $res->usort(fn(Department $a, Department $b) => $a->getSlug() <=> $b->getSlug());
    }

    public function numberOfTypeRecipes(RecipeType $type): int
    {
        return $this->meals->filter(fn(Meal $meal) => $meal->typeIs($type))->count();
    }

    public function hasRecipe(Recipe $recipe): bool
    {
        return $this->meals->find(fn(Meal $meal) => $meal->hasRecipe($recipe)) !== null;
    }

    public function isPlanning(): bool
    {
        return $this->status === Status::PLANNING;
    }

    public function isShopping(): bool
    {
        return $this->status === Status::SHOPPING;
    }

    public function isCompleted(): bool
    {
        return $this->status === Status::COMPLETED;
    }

    /**
     * @throws ShoppingListException
     */
    public function addMeal(Recipe $recipe): void
    {
        // Impossible de mettre la même recette deux fois dans une liste
        if ($this->hasRecipe($recipe)) {
            throw ShoppingListException::recipeAlreadyInList($recipe->getName());
        }

        // Ajoute à la liste des recettes avec le nombre de personnes de l'auteur
        $this->meals[] = Meal::create(
            shoppingListId: $this->id,
            recipe: $recipe,
            servings: $this->owner->getDefaultServing(),
        );
    }

    public function addRow(Ingredient $ingredient, float $quantity): void
    {
        // Cherche si la ligne avec l'ingrédient est déjà present dans la liste
        if (($row = $this->rows->find(fn(Row $row) => $row->getIngredient()->isEqual($ingredient))) !== null) {
            $row->addQuantity($quantity);
        } else {
            //Sinon on créer la ligne
            $this->rows[] = Row::create($this->id, $ingredient, $quantity);
        }
    }

    public function subtractRow(Ingredient $ingredient, float $quantity): void
    {
        // Cherche si la ligne avec l'ingrédient est déjà present dans la liste
        if (($rowKey = $this->rows->findKey(fn(Row $row) => $row->getIngredient()->isEqual($ingredient))) !== null) {
            $this->rows[$rowKey]->subtractQuantity($quantity);
            // si la quantité passe à zero ou moins, on supprime la row
            if ($this->rows[$rowKey]->getQuantity() <= 0) {
                unset($this->rows[$rowKey]);
            }
        }
    }

    public function setStatus(?Status $status): void
    {
        $this->status = $status;
    }

    public function getStatus(): Status
    {
        return $this->status;
    }

    /**
     * @throws ShoppingListException
     */
    public function toShopping(): void
    {
        if (!$this->isPlanning()) {
            throw ShoppingListException::shoppingListShouldBeOnPlanningBeforeShopping($this->id, $this->status);
        }

        $this->status = Status::SHOPPING;
    }

    /**
     * @throws ShoppingListException
     */
    public function toPlanning(): void
    {
        if (!$this->isShopping()) {
            throw ShoppingListException::shoppingListShouldBeOnShoppingBeforeRevertToPlanning($this->id, $this->status);
        }

        $this->status = Status::PLANNING;
    }

    public function addIngredient(Ingredient $ingredient): void
    {
        // Recherche de l'ingrédient dans l'unité d'achat dans la liste
        if (($shopListIngredient = $this->ingredients->find(fn(ShoppingListIngredient $sli) => $sli->getIngredient()->isEqual($ingredient) && $sli->getUnit()->isEqual($ingredient->getDefaultPurchaseUnit()))) !== null) {
            // Si on le trouve on incrémente de 1 la quantité
            $shopListIngredient->addQuantity(1);
        } else {
            // Sinon on le créer avec une quantité de 1
            $this->ingredients[] = ShoppingListIngredient::create(
                shoppingListId: $this->id,
                ingredient: $ingredient,
                unit: $ingredient->getDefaultPurchaseUnit(),
                quantity: 1
            );
        }
    }

    public function removeIngredient(Ingredient $ingredient): void
    {
        $ingredientKey = $this->ingredients->findKey(fn(ShoppingListIngredient $sli) => $sli->getIngredient()->isEqual($ingredient) && $sli->getUnit()->isEqual($ingredient->getDefaultPurchaseUnit()));

        // Si on ne retrouve pas l'ingrédient, il n'est deja plus present dans la liste
        if ($ingredientKey === null) {
            return;
        }

        // On soustrait 1 a la quantité
        $this->ingredients[$ingredientKey]->subtractQuantity(1);

        // Si la quantité est inférieur ou égal à zéro, alors on supprime l'ingrédient
        if ($this->ingredients[$ingredientKey]->getQuantity() <= 0) {
            unset($this->ingredients[$ingredientKey]);
        }
    }

    public function allLineTicked(): bool
    {
        return $this->rows->find(fn(ShoppingListRow $row) => !$row->isChecked()) === null;
    }

    public function completed(): void
    {
        $this->status = Status::COMPLETED;
    }

    public function activated(): void
    {
        $this->status = Status::ACTIVE;
    }

    public function removeMeal(Recipe $recipe): void
    {
        $mealKey = $this->meals->findKey(fn(Meal $meal) => $meal->hasRecipe($recipe));

        if ($mealKey === null) {
            // Le repas n'est déjà pas/plus présent dans la liste
            return ;
        }

        // On trouve le coefficient grace au serving
        unset($this->meals[$mealKey]);
    }

    public function countRows(): int
    {
        return $this->rows->count();
    }

    public function countRecipes(): int
    {
        return $this->meals->count();
    }

    public function getIngredientQuantity(Ingredient $ingredient): ?float
    {
        return $this->rows->find(fn(Row $row) => $row->getIngredient()->isEqual($ingredient))?->getQuantity();
    }
}
