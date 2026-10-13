<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Infrastructure\Loader\RecipeLoader;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\RepasAssert;
use Repas\User\Domain\Model\User;

/**
 * Chaque test crée ses auteurs et recettes (la base de test est vide au départ).
 */
class RecipeLoaderTest extends DatabaseTestCase
{
    private RecipeLoader $recipeLoader;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recipeLoader = static::getContainer()->get(RecipeLoader::class);
        $this->persister = new DataPersister(static::getContainer());
    }

    public function testFindByAuthorAndType(): void
    {
        // Arrange
        $author = $this->savedUser();
        $expected = [
            $this->savedRecipe(new RecipeBuilder()->isBasqueCake()->withAuthor($author))->getId(),
            $this->savedRecipe(new RecipeBuilder()->isBasqueCake()->withAuthor($author))->getId(),
        ];
        $this->savedRecipe(new RecipeBuilder()->isPastaCarbonara()->withAuthor($author));
        $this->savedRecipe(new RecipeBuilder()->isBasqueCake()->withAuthor($this->savedUser()));
        $dessert = new RecipeTypeBuilder()->isDessert()->build();

        // Act
        $result = $this->recipeLoader->findByAuthorAndType($author, $dessert);

        // Assert
        $this->assertEqualsCanonicalizing($expected, $this->idsOf($result));
        RepasAssert::assertTabType(Tab::newEmptyTyped(Recipe::class), $result);
        foreach ($result as $recipe) {
            $this->assertTrue($recipe->getType()->isEqual($dessert));
            $this->assertTrue($recipe->getAuthor()->isEqual($author));
            $this->assertEmpty($recipe->getRows());
        }
    }

    public function testFindBy(): void
    {
        // Arrange
        $author = $this->savedUser();
        $expected = [
            $this->savedRecipe(new RecipeBuilder()->isBasqueCake()->withAuthor($author))->getId(),
            $this->savedRecipe(new RecipeBuilder()->isBasqueCake()->withAuthor($author))->getId(),
        ];
        $this->savedRecipe(new RecipeBuilder()->isPastaCarbonara()->withAuthor($author));
        $dessert = new RecipeTypeBuilder()->isDessert()->build();

        // Act
        $result = $this->recipeLoader->findBy(['authorId' => $author->getId(), 'typeSlug' => 'dessert']);

        // Assert
        $this->assertEqualsCanonicalizing($expected, $this->idsOf($result));
        RepasAssert::assertTabType(Tab::newEmptyTyped(Recipe::class), $result);
        foreach ($result as $recipe) {
            $this->assertTrue($recipe->getType()->isEqual($dessert));
            $this->assertTrue($recipe->getAuthor()->isEqual($author));
            $this->assertEmpty($recipe->getRows());
        }
    }

    public function testFindByNotAuthorAndNotCopy(): void
    {
        // Arrange
        $john = $this->savedUser();
        $other = $this->savedUser();
        $original = $this->savedRecipe(new RecipeBuilder()->isPastaCarbonara()->withAuthor($other));
        $copyOfOriginal = $this->savedRecipe(new RecipeBuilder()->isPastaCarbonara()->withAuthor($this->savedUser())->withOriginalId($original->getId()));
        $ownRecipe = $this->savedRecipe(new RecipeBuilder()->isSoftBoiledEggs()->withAuthor($john));

        // Act
        $result = $this->recipeLoader->findByNotAuthorAndNotCopy($john);

        // Assert
        RepasAssert::assertTabType(Tab::newEmptyTyped(Recipe::class), $result);
        $ids = $this->idsOf($result);
        $this->assertContains($original->getId(), $ids);
        $this->assertNotContains($copyOfOriginal->getId(), $ids);
        $this->assertNotContains($ownRecipe->getId(), $ids);
        foreach ($result as $recipe) {
            $this->assertTrue($recipe->isOriginal());
            $this->assertFalse($recipe->getAuthor()->isEqual($john));
            $this->assertEmpty($recipe->getRows());
        }
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
