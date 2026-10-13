<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\RepasAssert;
use Repas\User\Domain\Model\User;

/**
 * Chaque test crée ses auteurs, recettes et ingrédients (la base de test est vide au départ).
 */
class RecipeRepositoryTest extends DatabaseTestCase
{
    private RecipeRepository $recipeRepository;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recipeRepository = static::getContainer()->get(RecipeRepository::class);
        $this->persister = new DataPersister(static::getContainer());
    }

    public function testCRUD(): void
    {
        // Arrange
        $user = $this->savedUser();
        $recipe = new RecipeBuilder()->isPastaCarbonara()->withAuthor($user)->build();
        $this->persister->saveRecipeDependencies($recipe);

        // Act
        $this->recipeRepository->save($recipe);

        // Assert
        $recipeLoaded = $this->recipeRepository->findOneById($recipe->getId());
        RepasAssert::assertRecipe($recipe, $recipeLoaded);

        // Arrange
        $recipe->setServing(10);
        $recipe->setName("nouveau petit nom");
        $recipe->setType(new RecipeTypeBuilder()->isDessert()->build());
        $recipe->setRows(Tab::fromArray(new RecipeRowBuilder()->withRecipeId($recipe->getId())->build()));
        $this->persister->saveRecipeDependencies($recipe);

        // Act
        $this->recipeRepository->save($recipe);

        // Assert
        $recipeLoaded = $this->recipeRepository->findOneById($recipe->getId());
        RepasAssert::assertRecipe($recipe, $recipeLoaded);
    }

    public function testFindByAuthor(): void
    {
        // Arrange
        $author = $this->savedUser();
        $first = $this->savedRecipe(new RecipeBuilder()->isPastaCarbonara()->withAuthor($author));
        $second = $this->savedRecipe(new RecipeBuilder()->isBasqueCake()->withAuthor($author));
        $this->savedRecipe(new RecipeBuilder()->isSoftBoiledEggs()->withAuthor($this->savedUser()));

        // Act
        $recipes = $this->recipeRepository->findByAuthor($author);

        // Assert
        $this->assertEqualsCanonicalizing([$first->getId(), $second->getId()], $this->idsOf($recipes));
        RepasAssert::assertTabType(Tab::newEmptyTyped(Recipe::class), $recipes);
    }

    public function testFindByIngredient(): void
    {
        // Arrange
        $author = $this->savedUser();
        $carbonara = $this->savedRecipe(new RecipeBuilder()->isPastaCarbonara()->withAuthor($author));
        $otherCarbonara = $this->savedRecipe(new RecipeBuilder()->isPastaCarbonara()->withAuthor($this->savedUser()));
        $withoutPasta = $this->savedRecipe(new RecipeBuilder()->isSoftBoiledEggs()->withAuthor($author));

        // Act
        $actual = $this->recipeRepository->findByIngredient(new IngredientBuilder()->isPasta()->build());

        // Assert
        RepasAssert::assertTabType(Tab::newEmptyTyped(Recipe::class), $actual);
        $ids = $this->idsOf($actual);
        $this->assertContains($carbonara->getId(), $ids);
        $this->assertContains($otherCarbonara->getId(), $ids);
        $this->assertNotContains($withoutPasta->getId(), $ids);
    }

    public function testDelete(): void
    {
        // Arrange
        $user = $this->savedUser();
        $recipe = $this->savedRecipe(new RecipeBuilder()->isPastaCarbonara()->withAuthor($user));

        // Act
        $this->recipeRepository->delete($recipe);
        static::getContainer()->get('doctrine.orm.entity_manager')->clear(); // Nettoie le cache Doctrine

        // Assert
        $this->expectException(\Exception::class); // Adapte si une exception spécifique est levée
        $this->recipeRepository->findOneById($recipe->getId());
    }

    public function testFindByAuthorAndType(): void
    {
        // Arrange
        $author = $this->savedUser();
        $dessert = $this->savedRecipe(new RecipeBuilder()->isBasqueCake()->withAuthor($author));
        $this->savedRecipe(new RecipeBuilder()->isPastaCarbonara()->withAuthor($author));
        $this->savedRecipe(new RecipeBuilder()->isBasqueCake()->withAuthor($this->savedUser()));
        $type = new RecipeTypeBuilder()->isDessert()->build();

        // Act
        $recipes = $this->recipeRepository->findByAuthorAndType($author, $type);

        // Assert
        $this->assertSame([$dessert->getId()], $this->idsOf($recipes));
        RepasAssert::assertTabType(Tab::newEmptyTyped(Recipe::class), $recipes);
    }

    public function testFindBy(): void
    {
        // Arrange
        $author = $this->savedUser();
        $small = $this->savedRecipe(new RecipeBuilder()->isPastaCarbonara()->withName('zzz pates test')->withServing(2)->withAuthor($author));
        $big = $this->savedRecipe(new RecipeBuilder()->isPastaCarbonara()->withName('zzz pates test')->withServing(8)->withAuthor($author));
        $this->savedRecipe(new RecipeBuilder()->isSoftBoiledEggs()->withAuthor($author));
        $criteria = ['name' => 'zzz pates test'];
        $orderBy = ['serving' => 'DESC'];

        // Act
        $recipes = $this->recipeRepository->findBy($criteria, $orderBy);

        // Assert
        $this->assertSame([$big->getId(), $small->getId()], $this->idsOf($recipes));
        RepasAssert::assertTabType(Tab::newEmptyTyped(Recipe::class), $recipes);
    }

    private function savedUser(): User
    {
        $user = new UserBuilder()->build();
        $this->persister->saveUser($user);

        return $user;
    }

    private function savedRecipe(RecipeBuilder $builder): Recipe
    {
        $recipe = $builder->build();
        $this->persister->saveRecipe($recipe);

        return $recipe;
    }

    /**
     * @param Tab<Recipe> $recipes
     * @return list<string>
     */
    private function idsOf(Tab $recipes): array
    {
        return array_values(array_map(static fn(Recipe $recipe): string => $recipe->getId(), $recipes->toArray()));
    }
}
