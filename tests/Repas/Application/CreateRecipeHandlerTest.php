<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\CreateRecipe\CreateRecipeCommand;
use Repas\Repas\Application\CreateRecipe\CreateRecipeHandler;
use Repas\Repas\Application\CreateRecipe\CreateRecipeRowSubCommand;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\RecipeInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\RecipeTypeInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UnitInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UserInMemoryRepository;
use Repas\Tests\Helper\RepasAssert;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;

class CreateRecipeHandlerTest extends TestCase
{
    private readonly CreateRecipeHandler $handler;
    private readonly RecipeTypeRepository $recipeTypeRepository;
    private readonly UserRepository $userRepository;
    private readonly IngredientRepository $ingredientRepository;
    private readonly UnitRepository $unitRepository;
    private readonly RecipeRepository $recipeRepository;

    protected function setUp(): void
    {
        $baby = new DepartmentBuilder()->isBaby()->build();

        $this->userRepository = new UserInMemoryRepository();
        $this->ingredientRepository = new IngredientInMemoryRepository();
        $this->unitRepository = new UnitInMemoryRepository([
            new UnitBuilder()->isUnite()->build(),
            new UnitBuilder()->isGramme()->build(),
        ]);
        $this->recipeTypeRepository = new RecipeTypeInMemoryRepository([
            new RecipeTypeBuilder()->isMeal()->build(),
            new RecipeTypeBuilder()->isDessert()->build(),
        ]);
        $this->recipeRepository = new RecipeInMemoryRepository();

        $this->handler = new CreateRecipeHandler(
            $this->userRepository,
            $this->recipeTypeRepository,
            $this->ingredientRepository,
            $this->unitRepository,
            $this->recipeRepository,
        );
    }

    public function testHandleSuccessfullyCreateRecipe(): void
    {
        // Arrange
        $user = new UserBuilder()->build();
        $this->userRepository->save($user);
        $pasta = new IngredientBuilder()->isPasta()->build();
        $this->ingredientRepository->save($pasta);
        $recipeType = new RecipeTypeBuilder()->isMeal()->build();

        $rows = new Tab([], CreateRecipeRowSubCommand::class);
        $command = new CreateRecipeCommand(
            id: "unique_id",
            name: "Gloubiboulga",
            serving: 25,
            authorId: $user->getId(),
            rows: $rows,
            typeSlug: $recipeType->getSlug(),
        );

        $rows[] = new CreateRecipeRowSubCommand(
            ingredientSlug: $pasta->getSlug(),
            unitSlug: 'gramme',
            quantity: 10,
        );

        // Act
        ($this->handler)($command);

        // Assert
        $expected = new RecipeBuilder()
            ->withId("unique_id")
            ->withName("Gloubiboulga")
            ->withServing(25)
            ->withAuthor($user)
            ->addRow(
                new RecipeRowBuilder()
                ->withRecipeId("unique_id")
                ->withQuantity(10)
                ->withUnit(new UnitBuilder()->isGramme())
                ->withIngredient($pasta)
            )
            ->withRecipeType($recipeType)
            ->build();
        $actual = $this->recipeRepository->findOneById("unique_id");
        RepasAssert::assertRecipe($expected, $actual, ["RecipeRow" => ["id"]]);

        $actualUser = $this->userRepository->findOneById($user->getId());
        $this->assertEquals(1, $actualUser->getRecipeStats());
    }


    public function testHandleCreateRecipeFailedUserNotFound(): void
    {
        // Arrange
        $user = new UserBuilder()->build();
        $pasta = new IngredientBuilder()->isPasta()->build();
        $this->ingredientRepository->save($pasta);
        $recipeType = new RecipeTypeBuilder()->isMeal()->build();

        $rows = new Tab([], CreateRecipeRowSubCommand::class);
        $command = new CreateRecipeCommand(
            id: "unique_id",
            name: "Gloubiboulga",
            serving: 25,
            authorId: $user->getId(),
            rows: $rows,
            typeSlug: $recipeType->getSlug(),
        );

        $rows[] = new CreateRecipeRowSubCommand(
            ingredientSlug: $pasta->getSlug(),
            unitSlug: 'gramme',
            quantity: 10,
        );

        // Assert
        $this->expectExceptionObject(UserException::NotFound($user->getId()));

        // Act
        ($this->handler)($command);
    }

    public function testHandleCreateRecipeFailedIngredientNotFound(): void
    {
        // Arrange
        $user = new UserBuilder()->build();
        $this->userRepository->save($user);
        $pasta = new IngredientBuilder()->isPasta()->build();
        $recipeType = new RecipeTypeBuilder()->isMeal()->build();

        $rows = new Tab([], CreateRecipeRowSubCommand::class);
        $command = new CreateRecipeCommand(
            id: "unique_id",
            name: "Gloubiboulga",
            serving: 25,
            authorId: $user->getId(),
            rows: $rows,
            typeSlug: $recipeType->getSlug(),
        );

        $rows[] = new CreateRecipeRowSubCommand(
            ingredientSlug: $pasta->getSlug(),
            unitSlug: 'gramme',
            quantity: 10,
        );

        // Assert
        $this->expectExceptionObject(IngredientException::NotFound($pasta->getSlug()));

        // Act
        ($this->handler)($command);
    }

    public function testHandleCreateRecipeFailedRecipeTypeNotFound(): void
    {
        // Arrange
        $user = new UserBuilder()->build();
        $this->userRepository->save($user);
        $pasta = new IngredientBuilder()->isPasta()->build();
        $this->ingredientRepository->save($pasta);
        $recipeType = new RecipeTypeBuilder()->isBreakfast()->build();

        $rows = new Tab([], CreateRecipeRowSubCommand::class);
        $command = new CreateRecipeCommand(
            id: "unique_id",
            name: "Gloubiboulga",
            serving: 25,
            authorId: $user->getId(),
            rows: $rows,
            typeSlug: $recipeType->getSlug(),
        );

        $rows[] = new CreateRecipeRowSubCommand(
            ingredientSlug: $pasta->getSlug(),
            unitSlug: 'gramme',
            quantity: 10,
        );

        // Assert
        $this->expectExceptionObject(RecipeException::typeNotFound($recipeType->getSlug()));

        // Act
        ($this->handler)($command);
    }
}
