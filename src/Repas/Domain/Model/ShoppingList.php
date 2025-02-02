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
        return $this->rows;
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
            status: Status::PLANNING,
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
    public function departmentPresent(): Tab
    {
        $res = Tab::newEmptyTyped(Department::class);
        foreach ($this->meals as $meal) {
            $res = $res->merge($meal->departmentPresent());
        }
        return $res;
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
        if (!$this->isPlanning()) {
            throw ShoppingListException::cannotAddRecipeToShoppingListUnlessPlanning($this->id);
        }

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

        // Ajoutes les ingredients de la recette à la liste de course
        // en prenant en compte les bonnes quantités : Coefficient = owner.defaultServing / recipe.serving
        $coefficient = $this->owner->getDefaultServing() / $recipe->getServing();
        foreach ($recipe->getRows() as $recipeRow) {
            $callback = fn(ShoppingListIngredient $spi) => $spi->hasIngredientInUnit($recipeRow->getIngredient(), $recipeRow->getUnit());
            $quantity = $recipeRow->getQuantity() * $coefficient;
            if (($shoppingListIngredient = $this->ingredients->find($callback)) !== null) {
                // Si un couple ingredient-unité existe, on les additionne
                $shoppingListIngredient->addQuantity($quantity);
            } else {
                // Ajoute un nouveau couple ingredient-unité
                $this->ingredients[] = ShoppingListIngredient::create(
                    shoppingListId: $this->id,
                    ingredient: $recipeRow->getIngredient(),
                    unit: $recipeRow->getUnit(),
                    quantity: $quantity,
                );
            }
        }
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

        // On reset les lignes
        $this->rows = Tab::newEmptyTyped(Row::class);
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

    private function foundRowByIngredientAndUnit(Ingredient $ingredient, Unit $unit): ?ShoppingListIngredient
    {
        return $this->ingredients->find(fn(ShoppingListIngredient $row) => $row->hasIngredientInUnit($ingredient, $unit));
    }

    /**
     * @throws ShoppingListException
     */
    public function removeMeal(Recipe $recipe): void
    {
        if (!$this->isPlanning()) {
            throw ShoppingListException::cannotRemoveRecipeToShoppingListUnlessPlanning($this->id);
        }

        $mealKey = $this->meals->findKey(fn(Meal $meal) => $meal->hasRecipe($recipe));

        if ($mealKey === null) {
            // Le repas n'est déjà pas/plus présent dans la liste
            return ;
        }

        // On trouve le coefficient grace au serving
        $coefficient = $this->meals[$mealKey]->getServing() / $recipe->getServing();
        unset($this->meals[$mealKey]);

        // Supprime les ingredients de la recette
        foreach ($recipe->getRows() as $recipeRow) {
            $callback = fn(ShoppingListIngredient $spi) => $spi->hasIngredientInUnit($recipeRow->getIngredient(), $recipeRow->getUnit());
            $quantity = $recipeRow->getQuantity() * $coefficient;
            if (($listIngredientKey = $this->ingredients->findKey($callback)) !== null) {
                if ($this->ingredients[$listIngredientKey]->getQuantity() === $quantity) {
                    // Si la quantité est égale, alors on supprime l'ingrédient
                    unset($this->ingredients[$listIngredientKey]);
                } else {
                    // Si la quantité est supérieur, alors on soustrait la quantité
                    $this->ingredients[$listIngredientKey]->subtractQuantity($quantity);
                }
            }
        }
    }
}
