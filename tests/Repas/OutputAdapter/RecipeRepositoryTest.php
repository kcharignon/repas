<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Infrastructure\Entity\RecipeRow as RecipeRowEntity;
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
        $recipes = $this->recipeRepository->findByAuthor($author->getId());

        // Assert
        $this->assertEqualsCanonicalizing([$first->getId(), $second->getId()], $this->idsOf($recipes));
        RepasAssert::assertTabType(Tab::newEmptyTyped(Recipe::class), $recipes);
    }

    public function testIsIngredientUsedTellsIfAnyRecipeUsesTheIngredient(): void
    {
        // Arrange : les pâtes sont dans une recette, le lait dans aucune
        $this->savedRecipe(new RecipeBuilder()->isPastaCarbonara()->withAuthor($this->savedUser()));
        $milk = new IngredientBuilder()->isMilk()->build();
        $this->persister->saveIngredient($milk);
        $pasta = new IngredientBuilder()->isPasta()->build();

        // Act & Assert
        $this->assertTrue($this->recipeRepository->isIngredientUsed($pasta->getSlug()));
        $this->assertFalse($this->recipeRepository->isIngredientUsed($milk->getSlug()));
        $this->assertFalse($this->recipeRepository->isIngredientUsed('inconnu'));
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

    public function testDeleteRemovesTheRowsOfTheRecipeToo(): void
    {
        // Arrange
        $user = $this->savedUser();
        $recipe = $this->savedRecipe(new RecipeBuilder()->isPastaCarbonara()->withAuthor($user));
        $otherRecipe = $this->savedRecipe(new RecipeBuilder()->isSoftBoiledEggs()->withAuthor($user));
        $rowsOf = fn(string $recipeId): int => (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(rr.id)')
            ->from(RecipeRowEntity::class, 'rr')
            ->where('rr.recipeId = :recipeId')
            ->setParameter('recipeId', $recipeId)
            ->getQuery()
            ->getSingleScalarResult();
        $this->assertGreaterThan(0, $rowsOf($recipe->getId()));

        // Act
        $this->recipeRepository->delete($recipe);

        // Assert : plus aucune ligne orpheline, celles des autres recettes sont intactes
        $this->assertSame(0, $rowsOf($recipe->getId()));
        $this->assertSame($otherRecipe->getRows()->count(), $rowsOf($otherRecipe->getId()));
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
