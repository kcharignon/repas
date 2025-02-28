<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\CopyRecipe\CopyRecipeCommand;
use Repas\Repas\Application\CopyRecipe\CopyRecipeHandler;
use Repas\Repas\Domain\Event\RecipesOrIngredientsCreatedEvent;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\ConversionInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\RecipeInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UserInMemoryRepository;
use Repas\Tests\Helper\RepasAssert;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class CopyRecipeHandlerTest extends TestCase
{
    private readonly CopyRecipeHandler $handler;
    private readonly RecipeRepository $recipeRepository;
    private readonly UserRepository $userRepository;
    private readonly IngredientRepository $ingredientRepository;
    private readonly ConversionRepository $conversionRepository;
    private readonly EventDispatcherInterface $eventDispatcher;
    private User $user;
    private IngredientBuilder $secretIngredient;
    private ConversionBuilder $secretIngredientConversion;

    protected function setUp(): void
    {
        $owner = new UserBuilder()->withId('owner-id')->build();
        $this->user = new UserBuilder()->withId('user-id')->build();
        $this->userRepository = new UserInMemoryRepository([$owner, $this->user]);
        $box = new UnitBuilder()->isBox();
        $unit = new UnitBuilder()->isUnite();
        $this->secretIngredient = new IngredientBuilder()
            ->withName("ingredient secret")
            ->withCreator($owner)
            ->withDefaultCookingUnit($unit)
            ->withDefaultPurchaseUnit($box)
            ->withCompatibleUnits([$unit, $box])
        ;
        $this->ingredientRepository = new IngredientInMemoryRepository([$this->secretIngredient->build()]);
        $this->secretIngredientConversion = new ConversionBuilder()
            ->withIngredient($this->secretIngredient)
            ->withStartUnit($box)
            ->withEndUnit($unit)
            ->withCoefficient(10);
        $this->conversionRepository = new ConversionInMemoryRepository([
            $this->secretIngredientConversion->build(),
        ]);
        $this->recipeRepository = new RecipeInMemoryRepository([
            new RecipeBuilder()
                ->withId('recipe-id')
                ->isPastaCarbonara()
                ->addRow(new RecipeRowBuilder()
                    ->withIngredient($this->secretIngredient)
                )
                ->withAuthor($owner)
                ->build(),
        ]);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->handler = new CopyRecipeHandler(
            $this->recipeRepository,
            $this->userRepository,
            $this->ingredientRepository,
            $this->conversionRepository,
            $this->eventDispatcher,
        );
    }


    public function testSuccessfullyHandleCopyRecipe(): void
    {
        // Arrange
        $command = new CopyRecipeCommand('recipe-id', 'user-id');

        // Act
        ($this->handler)($command);

        // Assert
        $actual = $this->recipeRepository->findByAuthor($this->user)->reset();
        $expected = new RecipeBuilder()
            ->withId($actual->getId())
            ->withAuthor($this->user)
            ->isPastaCarbonara()
            ->addRow(new RecipeRowBuilder()->withIngredient($this->secretIngredient->withCreator($this->user, true)->build()))
            ->build();
        RepasAssert::assertRecipe($expected, $actual, ['RecipeRow' => ['id']]);

        $actualIngredient = $this->ingredientRepository->findByOwner($this->user)->reset();
        $expectedIngredient = $this->secretIngredient->withCreator($this->user)->build();
        RepasAssert::assertIngredient($expectedIngredient, $actualIngredient);

        $actualConversion = $this->conversionRepository->findByIngredient($actualIngredient)->reset();
        $expectedConversion = $this->secretIngredientConversion->withIngredient($actualIngredient)->build();
        RepasAssert::assertConversion($expectedConversion, $actualConversion, ['id']);
    }

    public function testFailedHandleCopyRecipeUnknownUser(): void
    {
        // Arrange
        $command = new CopyRecipeCommand('recipe-id', 'not-found');

        // Assert
        $this->expectExceptionObject(UserException::NotFound('not-found'));

        // Act
        ($this->handler)($command);
    }

    public function testFailedHandleCopyRecipeUnknownRecipe(): void
    {
        // Arrange
        $command = new CopyRecipeCommand('not-found', 'user-id');

        // Assert
        $this->expectExceptionObject(RecipeException::NotFound('not-found'));

        // Act
        ($this->handler)($command);
    }
}
