<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\PurgeUserData\PurgeUserDataCommand;
use Repas\Repas\Application\Command\PurgeUserData\PurgeUserDataHandler;
use Repas\Repas\Domain\Model\AliasKind;
use Repas\Repas\Domain\Model\IngredientAlias;
use Repas\Repas\Domain\Model\IngredientDistinctPair;
use Repas\Repas\Domain\Model\Meal;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\ConversionInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientAliasInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientDistinctInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\RecipeInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;

class PurgeUserDataHandlerTest extends TestCase
{
    private const string USER = 'user-id';
    private const string OTHER = 'other-id';

    private ShoppingListInMemoryRepository $shoppingListRepository;
    private RecipeInMemoryRepository $recipeRepository;
    private ConversionInMemoryRepository $conversionRepository;
    private IngredientInMemoryRepository $ingredientRepository;
    private IngredientAliasInMemoryRepository $aliasRepository;
    private IngredientDistinctInMemoryRepository $distinctRepository;
    private PurgeUserDataHandler $handler;

    protected function setUp(): void
    {
        $user = new UserBuilder()->withId(self::USER);
        $other = new UserBuilder()->withId(self::OTHER);

        $userRecipe = new RecipeBuilder()->withId('user-recipe')->withAuthor($user)->isSoftBoiledEggs()->build();
        $userSecondRecipe = new RecipeBuilder()->withId('user-second-recipe')->withAuthor($user)->isBasqueCake()->build();
        $otherRecipe = new RecipeBuilder()->withId('other-recipe')->withAuthor($other)->isPastaCarbonara()->build();
        $copyOfUserRecipe = new RecipeBuilder()->withId('copy-of-user-recipe')->withAuthor($other)->isSoftBoiledEggs()->withOriginalId('user-recipe')->build();
        $this->recipeRepository = new RecipeInMemoryRepository([$userRecipe, $userSecondRecipe, $otherRecipe, $copyOfUserRecipe]);

        // Une liste ne peut plus planifier la recette d'un autre ; une donnée plus ancienne le peut encore (le repas est ajouté sans passer par le modèle)
        $otherList = new ShoppingListBuilder()->withId('other-list')->withOwner($other)->addRecipe($otherRecipe)->build();
        $otherList->getMeals()[] = Meal::create('legacy-meal', $otherList->getId(), $userSecondRecipe->getId(), 4);

        $this->shoppingListRepository = new ShoppingListInMemoryRepository([
            new ShoppingListBuilder()->withId('user-list')->withOwner($user)->addRecipe($userRecipe)->build(),
            $otherList,
        ]);

        $userCustomButter = new IngredientBuilder()->isButter()->withCreator($user->build(), true);
        $otherCustomButter = new IngredientBuilder()->isButter()->withCreator($other->build(), true);
        $this->ingredientRepository = new IngredientInMemoryRepository([
            $userCustomButter->build(),
            $otherCustomButter->build(),
            new IngredientBuilder()->isFlour()->build(),
        ]);
        $this->conversionRepository = new ConversionInMemoryRepository([
            new ConversionBuilder()->isBlockToGrammeForButter()->withIngredient($userCustomButter)->withId('user-conversion')->build(),
            new ConversionBuilder()->isBlockToGrammeForButter()->withIngredient($otherCustomButter)->withId('other-conversion')->build(),
            new ConversionBuilder()->isKiloToGramme()->withoutIngredient()->withId('common-conversion')->build(),
        ]);

        $this->aliasRepository = new IngredientAliasInMemoryRepository([
            IngredientAlias::create(self::USER, 'beurre', 'beurre-user', AliasKind::ADOPTION, 'first-use', new \DateTimeImmutable()),
            IngredientAlias::create(self::USER, 'farine', 'farine-user', AliasKind::ADOPTION, 'migration-2026-10-21', new \DateTimeImmutable()),
            IngredientAlias::create(self::OTHER, 'beurre', 'beurre-other', AliasKind::ADOPTION, 'first-use', new \DateTimeImmutable()),
        ]);

        $this->distinctRepository = new IngredientDistinctInMemoryRepository([
            IngredientDistinctPair::create(self::USER, 'beurre-user', 'farine-user', new \DateTimeImmutable()),
            IngredientDistinctPair::create(self::OTHER, 'beurre-other', 'farine-other', new \DateTimeImmutable()),
        ]);

        $this->handler = new PurgeUserDataHandler(
            $this->shoppingListRepository,
            $this->recipeRepository,
            $this->conversionRepository,
            $this->ingredientRepository,
            $this->aliasRepository,
            $this->distinctRepository,
        );
    }

    public function testTheShoppingListsOfTheUserAreDeletedAndTheOthersKept(): void
    {
        // When
        ($this->handler)(new PurgeUserDataCommand(self::USER));

        // Then
        $this->assertCount(0, $this->shoppingListRepository->findByOwner(self::USER));
        $this->assertCount(1, $this->shoppingListRepository->findByOwner(self::OTHER));
    }

    public function testTheRecipesOfTheUserAreDeletedAndTheOthersKept(): void
    {
        // When
        ($this->handler)(new PurgeUserDataCommand(self::USER));

        // Then
        $this->assertCount(0, $this->recipeRepository->findByAuthor(self::USER));
        $this->assertSame(
            ['copy-of-user-recipe', 'other-recipe'],
            $this->sortedIds($this->recipeRepository->findIdsByAuthor(self::OTHER)),
        );
    }

    public function testACopyMadeByAnotherUserStaysAsIfItWasTheOriginal(): void
    {
        // When
        ($this->handler)(new PurgeUserDataCommand(self::USER));

        // Then
        $copy = $this->recipeRepository->findOneById('copy-of-user-recipe');
        $this->assertNull($copy->getOriginalId());
        $this->assertTrue($copy->isOriginal());
        $this->assertSame(self::OTHER, $copy->getAuthorId());
    }

    public function testAMealOfAnotherUsersListThatPlansARecipeOfTheUserIsRemoved(): void
    {
        // When
        ($this->handler)(new PurgeUserDataCommand(self::USER));

        // Then
        $meals = $this->shoppingListRepository->findOneById('other-list')->getMeals();
        $this->assertSame(['other-recipe'], array_values($meals->map(fn(Meal $meal) => $meal->getRecipeId())->toArray()));
    }

    public function testTheCustomIngredientsAndTheirConversionsAreDeletedButNotTheCommonOnes(): void
    {
        // When
        ($this->handler)(new PurgeUserDataCommand(self::USER));

        // Then
        $this->assertCount(0, $this->ingredientRepository->findByOwner(self::USER));
        $this->assertCount(1, $this->ingredientRepository->findByOwner(self::OTHER));
        $this->assertCount(2, $this->ingredientRepository->findAll());
        $this->assertSame(
            ['common-conversion', 'other-conversion'],
            $this->sortedIds($this->conversionRepository->findAll()->map(fn($conversion) => $conversion->getId())->toArray()),
        );
    }

    public function testTheAliasesOfTheUserAreDeletedAndTheOthersKept(): void
    {
        // When
        ($this->handler)(new PurgeUserDataCommand(self::USER));

        // Then
        $this->assertNull($this->aliasRepository->find(self::USER, 'beurre'));
        $this->assertNull($this->aliasRepository->find(self::USER, 'farine'));
        $this->assertNotNull($this->aliasRepository->find(self::OTHER, 'beurre'));
    }

    public function testTheDistinctPairsOfTheUserAreDeletedAndTheOthersKept(): void
    {
        // When
        ($this->handler)(new PurgeUserDataCommand(self::USER));

        // Then
        $this->assertSame([self::OTHER . '|beurre-other|farine-other'], array_map(static fn(IngredientDistinctPair $pair): string => $pair->getId(), $this->distinctRepository->all()));
    }

    public function testAUserWithoutAnyDataIsNotAnError(): void
    {
        // When
        ($this->handler)(new PurgeUserDataCommand('nobody-id'));

        // Then
        $this->assertCount(2, $this->recipeRepository->findByAuthor(self::USER));
        $this->assertCount(2, $this->recipeRepository->findByAuthor(self::OTHER));
        $this->assertCount(1, $this->shoppingListRepository->findByOwner(self::USER));
        $this->assertCount(1, $this->shoppingListRepository->findByOwner(self::OTHER));
        $this->assertCount(3, $this->ingredientRepository->findAll());
        $this->assertCount(3, $this->conversionRepository->findAll());
        $this->assertCount(2, $this->distinctRepository->all());
    }

    /**
     * @param array<string|int, string> $ids
     * @return list<string>
     */
    private function sortedIds(array $ids): array
    {
        $ids = array_values($ids);
        sort($ids);

        return $ids;
    }
}
