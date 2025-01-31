<?php

namespace Repas\Repas\Domain\Model;


use DateTimeImmutable;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Domain\Model\User;

class ShoppingList implements ModelInterface
{
    use ModelTrait;

    /** @var Tab<RecipeType>|null  */
    private ?Tab $recipeTypes = null;

    /**
     * @param Tab<Meal> $meals
     */
    private function __construct(
        private string            $id,
        private User              $owner,
        private DateTimeImmutable $createdAt,
        private bool              $locked,
        private Tab               $meals,
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

    public function isLocked(): bool
    {
        return $this->locked;
    }

    public function getMeals(): Tab
    {
        return $this->meals;
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
            locked: false,
            meals: Tab::newEmptyTyped(Meal::class),
        );
    }

    public static function load(array $datas): static
    {
        return new static(
            id: $datas['id'],
            owner: $datas['owner'],
            createdAt: $datas['created_at'],
            locked: $datas['locked'],
            meals: $datas['meals'],
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
        return $this->recipeTypes;
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


    public function getShoppingListRow(): Tab
    {
        return Tab::fromArray([]);
    }

    public function lock(): void
    {
        $this->locked = true;
    }

    public function unlock(): void
    {
        $this->locked = false;
    }

    public function hasRecipe(Recipe $recipe): bool
    {
        return $this->meals->find(fn(Meal $meal) => $meal->hasRecipe($recipe)) !== null;
    }

    /**
     * @throws ShoppingListException
     */
    public function addRecipe(Recipe $recipe): void
    {
        if ($this->locked) {
            throw ShoppingListException::cantAddRecipeInLockedList($this->id);
        }

        // Impossible de mettre la même recette deux fois dans une liste
        if ($this->hasRecipe($recipe)) {
            throw ShoppingListException::recipeAlreadyInList($recipe->getName());
        }

        // Ajoute à la liste des recettes
        $this->meals->add(Meal::create(
            id: UuidGenerator::new(),
            shoppingListId: $this->id,
            recipe: $recipe,
            servings: $recipe->getServing(),
        ));

        // Ajoutes les ingredients de la recette
        // TODO: implement this part
    }

    /**
     * @throws ShoppingListException
     */
    public function removeMeal(Recipe $recipe): void
    {
        if ($this->locked) {
            throw ShoppingListException::cantRemoveRecipeInLockedList($this->id);
        }

        $mealKey = $this->meals->findKey(fn(Meal $meal) => $meal->hasRecipe($recipe));

        if ($mealKey === null) {
            // Le repas n'est déjà pas/plus présent dans la liste
            return ;
        }

        unset($this->meals[$mealKey]);

        // Supprime les ingredients de la recette
        // TODO: implement this part
    }
}
