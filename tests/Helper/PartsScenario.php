<?php

namespace Repas\Tests\Helper;


use Repas\Repas\Application\Command\AddIngredientToShoppingList\AddIngredientToShoppingListCommand;
use Repas\Repas\Application\Command\AddIngredientToShoppingList\AddIngredientToShoppingListHandler;
use Repas\Repas\Application\Command\PlannedMeal\PlannedMealCommand;
use Repas\Repas\Application\Command\PlannedMeal\PlannedMealHandler;
use Repas\Repas\Application\Command\RemoveIngredientToShoppingList\RemoveIngredientToShoppingListCommand;
use Repas\Repas\Application\Command\RemoveIngredientToShoppingList\RemoveIngredientToShoppingListHandler;
use Repas\Repas\Application\Command\RemoveMealFromPlan\removeMealFromPlanCommand;
use Repas\Repas\Application\Command\RemoveMealFromPlan\removeMealFromPlanHandler;
use Repas\Repas\Application\Command\UpdateServingMeal\UpdateServingMealCommand;
use Repas\Repas\Application\Command\UpdateServingMeal\UpdateServingMealHandler;
use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Repas\Application\Service\PartAwareLines;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListRow;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\ConversionInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientAliasInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\RecipeInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UnitInMemoryRepository;
use Repas\User\Domain\Model\User;

/**
 * Les sous-ingrédients dans la liste de courses (ADR 0038, SUB-003), en mémoire : de quoi planifier, changer les personnes, retirer une recette et
 * ajouter ou retirer un produit à la main quand des ingrédients sont des PARTIES d'autres. Un propriétaire pour quatre personnes et :
 *
 *   - « Œuf » (parent), acheté à l'unité, dont « Jaune d'œuf » et « Blanc d'œuf » sont des parties (ratio 1) ;
 *   - « Gousse de vanille » (parent), dont « Demi-gousse » (ratio 2) et « Graines » (ratio 4) sont des parties ;
 *   - « Farine » (ordinaire, cuisinée au gramme, achetée au kilo), pour prouver qu'un ingrédient ordinaire fait les mêmes écritures qu'avant.
 *
 * Les recettes se créent à la demande (`recipe()`), pour quatre personnes : leurs quantités sont celles de la liste pour quatre personnes.
 */
final class PartsScenario
{
    public const int SERVING = 4;

    public User $owner;
    public Ingredient $egg;
    public Ingredient $yolk;
    public Ingredient $white;
    public Ingredient $pod;
    public Ingredient $half;
    public Ingredient $seeds;
    public Ingredient $flour;
    public ShoppingListInMemoryRepository $lists;
    public IngredientInMemoryRepository $ingredients;
    public RecipeInMemoryRepository $recipes;
    public ConversionInMemoryRepository $conversionRepository;
    public ConversionService $conversions;
    public TypedLists $typed;

    public function __construct()
    {
        $this->owner = new UserBuilder()->withId('owner-id')->withServing(self::SERVING)->build();
        $this->egg = new IngredientBuilder()->isEgg()->withCreator($this->owner)->build();
        $this->yolk = $this->unitIngredient("Jaune d'œuf", 'jaune', $this->egg->getSlug(), 1.0);
        $this->white = $this->unitIngredient("Blanc d'œuf", 'blanc', $this->egg->getSlug(), 1.0);
        $this->pod = $this->unitIngredient('Gousse de vanille', 'vanille');
        $this->half = $this->unitIngredient('Demi-gousse', 'demi-gousse', $this->pod->getSlug(), 2.0);
        $this->seeds = $this->unitIngredient('Graines de vanille', 'graines', $this->pod->getSlug(), 4.0);
        $this->flour = new IngredientBuilder()->isFlour()->withCreator($this->owner)->build();

        $this->lists = new ShoppingListInMemoryRepository();
        $this->ingredients = new IngredientInMemoryRepository([$this->egg, $this->yolk, $this->white, $this->pod, $this->half, $this->seeds, $this->flour]);
        $this->recipes = new RecipeInMemoryRepository();
        $this->conversionRepository = new ConversionInMemoryRepository([new ConversionBuilder()->isKiloToGramme()->build()]);
        $this->conversions = new ConversionService($this->conversionRepository, new UnitInMemoryRepository());
        $this->typed = new TypedLists($this->lists, $this->ingredients);
    }

    private function unitIngredient(string $name, string $slug, ?string $parentSlug = null, float $ratio = 1.0): Ingredient
    {
        $builder = new IngredientBuilder()->withName($name)->withSlug($slug)->withDepartment(new DepartmentBuilder()->isMiscellaneous())
            ->withDefaultCookingUnit(new UnitBuilder()->isUnite())->withDefaultPurchaseUnit(new UnitBuilder()->isUnite())->withCreator($this->owner);
        if ($parentSlug !== null) {
            $builder->withParent($parentSlug, $ratio);
        }

        return $builder->build();
    }

    /**
     * Une recette de l'utilisateur pour quatre personnes (ou `$serving`) : `slug => quantité`, à l'unité (la farine, au gramme).
     *
     * @param array<string, int> $rows
     */
    public function recipe(string $id, array $rows, int $serving = self::SERVING): Recipe
    {
        $builder = new RecipeBuilder()->withId($id)->withName("Recette $id")->withServing($serving)->withAuthor($this->owner);
        foreach ($rows as $slug => $quantity) {
            $ingredient = $this->ingredients->findOneBySlug($slug);
            $builder->addRow(new RecipeRowBuilder()->withIngredient($ingredient)->withUnit($slug === $this->flour->getSlug() ? new UnitBuilder()->isGramme() : new UnitBuilder()->isUnite())->withQuantity($quantity));
        }
        $recipe = $builder->build();
        $this->recipes->save($recipe);

        return $recipe;
    }

    public function partLines(): PartAwareLines
    {
        return PartLines::for($this->ingredients, $this->conversions);
    }

    public function planned(): PlannedMealHandler
    {
        return new PlannedMealHandler($this->lists, $this->recipes, $this->conversions, new UuidGenerator(), $this->typed->routing, $this->typed->activeListForType, $this->partLines());
    }

    public function serving(): UpdateServingMealHandler
    {
        return new UpdateServingMealHandler($this->lists, $this->recipes, $this->conversions, $this->typed->group, $this->partLines());
    }

    public function removal(): removeMealFromPlanHandler
    {
        return new removeMealFromPlanHandler($this->lists, $this->recipes, $this->conversions, $this->typed->group);
    }

    public function adding(): AddIngredientToShoppingListHandler
    {
        return new AddIngredientToShoppingListHandler($this->lists, new IngredientSlugResolver(new IngredientAliasInMemoryRepository()), $this->typed->routing, $this->typed->activeListForType, $this->partLines());
    }

    public function removing(): RemoveIngredientToShoppingListHandler
    {
        return new RemoveIngredientToShoppingListHandler($this->lists, $this->ingredients, new IngredientSlugResolver(new IngredientAliasInMemoryRepository()), $this->typed->routing);
    }

    /** Planifie la recette pour quatre personnes (ou `$serving`). */
    public function plan(string $recipeId, int $serving = self::SERVING, ?int $expectedVersion = null): void
    {
        ($this->planned())(new PlannedMealCommand($this->owner->getId(), $recipeId, $serving, $expectedVersion));
    }

    public function changeServing(string $recipeId, int $serving): void
    {
        ($this->serving())(new UpdateServingMealCommand($this->mealIdOf($recipeId), $serving));
    }

    public function unplan(string $recipeId): void
    {
        ($this->removal())(new removeMealFromPlanCommand($this->owner->getId(), $recipeId));
    }

    public function add(string $ingredientSlug): void
    {
        ($this->adding())(new AddIngredientToShoppingListCommand($this->owner->getId(), $ingredientSlug));
    }

    public function remove(string $ingredientSlug): void
    {
        ($this->removing())(new RemoveIngredientToShoppingListCommand($this->owner->getId(), $ingredientSlug));
    }

    /**
     * Une liste du propriétaire, vide, enregistrée. `$typeId` : un type, ou `null` pour la liste principale.
     */
    public function list(string $id = 'main', ?string $typeId = null, ShoppingListStatus $status = ShoppingListStatus::ACTIVE): ShoppingList
    {
        $builder = new ShoppingListBuilder()->withId($id)->withOwner($this->owner)->withListTypeId($typeId);
        if ($status === ShoppingListStatus::SENT) {
            $builder->addIngredient(new IngredientBuilder()->isEgg());
        }
        $list = $builder->withStatus($status)->build();
        $this->lists->save($list);

        return $list;
    }

    /**
     * Les lignes d'une liste : `slug => quantité`, ordre des slugs. Une partie n'y est jamais.
     *
     * @return array<string, float>
     */
    public function rows(string $listId = 'main'): array
    {
        $rows = [];
        foreach ($this->lists->findOneById($listId)->getRows() as $row) {
            /** @var ShoppingListRow $row */
            $rows[$row->getIngredientSlug()] = $row->getQuantity();
        }
        ksort($rows);

        return $rows;
    }

    public function reload(string $listId = 'main'): ShoppingList
    {
        return $this->lists->findOneById($listId);
    }

    private function mealIdOf(string $recipeId): string
    {
        foreach ($this->lists->findByOwner($this->owner->getId()) as $list) {
            foreach ($list->getMeals() as $meal) {
                if ($meal->getRecipeId() === $recipeId) {
                    return $meal->getId();
                }
            }
        }

        throw new \LogicException("La recette $recipeId n'est planifiée nulle part.");
    }
}
