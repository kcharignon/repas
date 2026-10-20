<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\IngredientAliasRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\AliasKind;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\IngredientAlias;
use Repas\Repas\Domain\Model\Meal;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Model\User;

/**
 * Les suppressions en masse qui servent à effacer un compte (ADR 0021), sur la vraie base. Chaque test crée ses données :
 * un utilisateur « supprimé » (`$departing`) et un autre (`$staying`) dont rien ne doit bouger.
 */
class UserDataPurgeRepositoryTest extends DatabaseTestCase
{
    private ShoppingListRepository $shoppingListRepository;
    private RecipeRepository $recipeRepository;
    private IngredientRepository $ingredientRepository;
    private ConversionRepository $conversionRepository;
    private IngredientAliasRepository $aliasRepository;
    private DataPersister $persister;
    private User $departing;
    private User $staying;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        $this->shoppingListRepository = $container->get(ShoppingListRepository::class);
        $this->recipeRepository = $container->get(RecipeRepository::class);
        $this->ingredientRepository = $container->get(IngredientRepository::class);
        $this->conversionRepository = $container->get(ConversionRepository::class);
        $this->aliasRepository = $container->get(IngredientAliasRepository::class);
        $this->persister = new DataPersister($container);

        $this->departing = $this->savedUser();
        $this->staying = $this->savedUser();
    }

    public function testDeletingTheListsOfAnOwnerRemovesTheirMealsIngredientsAndRowsButNotTheOthers(): void
    {
        // Arrange
        $departingList = $this->savedListPlanning($this->departing, $this->savedRecipe($this->departing, new RecipeBuilder()->isPastaCarbonara()));
        $stayingList = $this->savedListPlanning($this->staying, $this->savedRecipe($this->staying, new RecipeBuilder()->isSoftBoiledEggs()));
        $this->assertGreaterThan(0, $this->countWhere('meal', 'shopping_list', $departingList->getId()));
        $this->assertGreaterThan(0, $this->countWhere('shopping_list_row', 'shopping_list', $departingList->getId()));

        // Act
        $this->shoppingListRepository->deleteByOwner($this->departing->getId());
        $this->entityManager->clear();

        // Assert
        $this->assertSame(0, $this->countWhere('shopping_list', 'id', $departingList->getId()));
        foreach (['meal', 'shopping_list_row', 'shopping_list_ingredient'] as $table) {
            $this->assertSame(0, $this->countWhere($table, 'shopping_list', $departingList->getId()), $table);
        }
        $this->assertSame(1, $this->countWhere('shopping_list', 'id', $stayingList->getId()));
        $this->assertSame(
            count($stayingList->getMeals()),
            $this->countWhere('meal', 'shopping_list', $stayingList->getId()),
        );
        $this->assertSame(
            count($stayingList->getRows()),
            $this->countWhere('shopping_list_row', 'shopping_list', $stayingList->getId()),
        );
    }

    public function testRemovingTheMealsOfRecipesAlsoReachesTheListsOfOtherUsers(): void
    {
        // Arrange
        $departingRecipe = $this->savedRecipe($this->departing, new RecipeBuilder()->isPastaCarbonara());
        $stayingRecipe = $this->savedRecipe($this->staying, new RecipeBuilder()->isSoftBoiledEggs());
        $stayingList = $this->savedListPlanning($this->staying, $stayingRecipe);
        // Une liste ne peut plus planifier la recette d'un autre ; une donnée plus ancienne le peut encore
        $stayingList->getMeals()[] = Meal::create('legacy-meal', $stayingList->getId(), $departingRecipe->getId(), 4);
        $this->shoppingListRepository->save($stayingList);
        $this->entityManager->clear();

        // Act
        $this->shoppingListRepository->removeMealsOfRecipes([$departingRecipe->getId()]);
        $this->entityManager->clear();

        // Assert : le repas de la recette supprimée disparaît, l'autre reste
        $this->assertSame(0, $this->countWhere('meal', 'recipe', $departingRecipe->getId()));
        $this->assertSame(1, $this->countWhere('meal', 'recipe', $stayingRecipe->getId()));
        $this->assertSame(1, $this->countWhere('meal', 'shopping_list', $stayingList->getId()));
    }

    public function testRemovingTheMealsOfNoRecipeChangesNothing(): void
    {
        // Arrange
        $recipe = $this->savedRecipe($this->staying, new RecipeBuilder()->isSoftBoiledEggs());
        $this->savedListPlanning($this->staying, $recipe);

        // Act
        $this->shoppingListRepository->removeMealsOfRecipes([]);
        $this->entityManager->clear();

        // Assert
        $this->assertSame(1, $this->countWhere('meal', 'recipe', $recipe->getId()));
    }

    public function testTheIdsOfTheRecipesOfAnAuthorAreListedWithoutTheOthers(): void
    {
        // Arrange
        $first = $this->savedRecipe($this->departing, new RecipeBuilder()->isPastaCarbonara());
        $second = $this->savedRecipe($this->departing, new RecipeBuilder()->isSoftBoiledEggs());
        $this->savedRecipe($this->staying, new RecipeBuilder()->isBasqueCake());

        // Act
        $ids = $this->recipeRepository->findIdsByAuthor($this->departing->getId());

        // Assert
        $expected = [$first->getId(), $second->getId()];
        sort($expected);
        sort($ids);
        $this->assertSame($expected, $ids);
        $this->assertSame([], $this->recipeRepository->findIdsByAuthor('nobody-id'));
    }

    public function testACopyBecomesAnOriginalRecipeWithANewVersionAndOtherCopiesAreUntouched(): void
    {
        // Arrange
        $original = $this->savedRecipe($this->departing, new RecipeBuilder()->isPastaCarbonara());
        $otherOriginal = $this->savedRecipe($this->staying, new RecipeBuilder()->isSoftBoiledEggs());
        $copy = $this->savedRecipe($this->staying, new RecipeBuilder()->isPastaCarbonara()->withOriginalId($original->getId()));
        $copyOfOther = $this->savedRecipe($this->departing, new RecipeBuilder()->isSoftBoiledEggs()->withOriginalId($otherOriginal->getId()));
        $versionBefore = $this->recipeRepository->findOneById($copy->getId())->getVersion();
        $otherVersionBefore = $this->recipeRepository->findOneById($copyOfOther->getId())->getVersion();
        $this->entityManager->clear();

        // Act
        $this->recipeRepository->makeCopiesOriginal([$original->getId()]);
        $this->entityManager->clear();

        // Assert
        $copy = $this->recipeRepository->findOneById($copy->getId());
        $this->assertNull($copy->getOriginalId());
        $this->assertTrue($copy->isOriginal());
        $this->assertSame($this->staying->getId(), $copy->getAuthorId());
        $this->assertSame($versionBefore + 1, $copy->getVersion());
        $this->assertSame($copyOfOther->getOriginalId(), $this->recipeRepository->findOneById($copyOfOther->getId())->getOriginalId());
        $this->assertSame($otherVersionBefore, $this->recipeRepository->findOneById($copyOfOther->getId())->getVersion());
    }

    public function testMakingTheCopiesOfNoRecipeOriginalChangesNothing(): void
    {
        // Arrange
        $original = $this->savedRecipe($this->departing, new RecipeBuilder()->isPastaCarbonara());
        $copy = $this->savedRecipe($this->staying, new RecipeBuilder()->isPastaCarbonara()->withOriginalId($original->getId()));
        $this->entityManager->clear();

        // Act
        $this->recipeRepository->makeCopiesOriginal([]);
        $this->entityManager->clear();

        // Assert
        $this->assertSame($original->getId(), $this->recipeRepository->findOneById($copy->getId())->getOriginalId());
    }

    public function testDeletingTheRecipesOfAnAuthorRemovesTheirRowsButNotTheOthers(): void
    {
        // Arrange
        $departingRecipe = $this->savedRecipe($this->departing, new RecipeBuilder()->isPastaCarbonara());
        $stayingRecipe = $this->savedRecipe($this->staying, new RecipeBuilder()->isSoftBoiledEggs());
        $this->assertSame(count($departingRecipe->getRows()), $this->countWhere('recipe_row', 'recipe', $departingRecipe->getId()));

        // Act
        $this->recipeRepository->deleteByAuthor($this->departing->getId());
        $this->entityManager->clear();

        // Assert
        $this->assertSame(0, $this->countWhere('recipe', 'id', $departingRecipe->getId()));
        $this->assertSame(0, $this->countWhere('recipe_row', 'recipe', $departingRecipe->getId()));
        $this->assertSame(1, $this->countWhere('recipe', 'id', $stayingRecipe->getId()));
        $this->assertSame(count($stayingRecipe->getRows()), $this->countWhere('recipe_row', 'recipe', $stayingRecipe->getId()));
    }

    public function testDeletingTheIngredientsOfACreatorRemovesTheirConversionsButNotTheCommonOnes(): void
    {
        // Arrange
        $departingButter = $this->savedCustomButter($this->departing);
        $stayingButter = $this->savedCustomButter($this->staying);
        $commonConversion = new ConversionBuilder()->isKiloToGramme()->withoutIngredient()->build();
        $this->persister->saveConversion($commonConversion);
        $departingConversion = new ConversionBuilder()->isBlockToGrammeForButter()->withIngredient($departingButter)->build();
        $this->persister->saveConversion($departingConversion);
        $stayingConversion = new ConversionBuilder()->isBlockToGrammeForButter()->withIngredient($stayingButter)->withId('staying-conversion')->build();
        $this->persister->saveConversion($stayingConversion);

        // Act
        $this->conversionRepository->deleteByIngredientCreator($this->departing->getId());
        $this->ingredientRepository->deleteByCreator($this->departing->getId());
        $this->entityManager->clear();

        // Assert
        $this->assertSame(0, $this->countWhere('conversion', 'id', $departingConversion->getId()));
        $this->assertSame(1, $this->countWhere('conversion', 'id', $stayingConversion->getId()));
        $this->assertSame(1, $this->countWhere('conversion', 'id', $commonConversion->getId()));
        $this->assertFalse($this->ingredientRepository->exists($departingButter->getSlug()));
        $this->assertTrue($this->ingredientRepository->exists($stayingButter->getSlug()));
    }

    public function testDeletingTheAliasesOfAUserRemovesHisAndNotTheOthers(): void
    {
        // Arrange : chacun a adopté le même commun
        foreach ([$this->departing, $this->staying] as $user) {
            $this->aliasRepository->save(IngredientAlias::create($user->getId(), 'beurre', 'beurre' . $user->getId(), AliasKind::ADOPTION, 'first-use', new \DateTimeImmutable()));
            $this->aliasRepository->save(IngredientAlias::create($user->getId(), 'farine', 'farine' . $user->getId(), AliasKind::ADOPTION, 'first-use', new \DateTimeImmutable()));
        }

        // Act
        $this->aliasRepository->deleteByUser($this->departing->getId());

        // Assert
        $this->assertSame(0, $this->countWhere('ingredient_alias', 'user_id', $this->departing->getId()));
        $this->assertSame(2, $this->countWhere('ingredient_alias', 'user_id', $this->staying->getId()));
    }

    private function savedUser(): User
    {
        $user = new UserBuilder()->build();
        $this->persister->saveUser($user);

        return $user;
    }

    private function savedRecipe(User $author, RecipeBuilder $builder): Recipe
    {
        $recipe = $builder->withAuthor($author)->build();
        $this->persister->saveRecipe($recipe);

        return $recipe;
    }

    private function savedCustomButter(User $creator): Ingredient
    {
        $butter = new IngredientBuilder()->isButter()->withCreator($creator, true)->build();
        $this->persister->saveIngredient($butter);

        return $butter;
    }

    private function savedListPlanning(User $owner, Recipe ...$recipes): ShoppingList
    {
        $builder = new ShoppingListBuilder()->withOwner($owner);
        foreach ($recipes as $recipe) {
            $builder->addRecipe($recipe);
        }
        $shoppingList = $builder->build();
        foreach ($recipes as $recipe) {
            foreach ($recipe->getRows() as $row) {
                $shoppingList->addRow($row->getIngredient()->getSlug(), $row->getIngredient()->getDefaultPurchaseUnit()->getSlug(), $row->getQuantity());
            }
        }
        $this->shoppingListRepository->save($shoppingList);

        return $shoppingList;
    }

    private function countWhere(string $table, string $column, string $value): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s WHERE %s = :value', $table, $column),
            ['value' => $value],
        );
    }
}
