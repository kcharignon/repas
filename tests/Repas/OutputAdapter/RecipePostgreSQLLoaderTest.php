<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Application\Loader\RecipeLoader;
use Repas\Repas\Application\Query\GetRecipe\RecipeRowDTO;
use Repas\Repas\Application\Query\ListOtherPeopleRecipes\OtherPeopleRecipeDTO;
use Repas\Repas\Application\Query\ListRecipesByType\RecipeSummaryDTO;
use Repas\Repas\Application\Query\ListRecipeTypes\RecipeTypeDTO;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Model\User;

class RecipePostgreSQLLoaderTest extends DatabaseTestCase
{
    private RecipeLoader $recipeLoader;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recipeLoader = static::getContainer()->get(RecipeLoader::class);
        $this->persister = new DataPersister(static::getContainer());
    }

    public function testGetReturnsTheRecipeWithItsRows(): void
    {
        // Given
        $author = $this->savedUser();
        $recipe = new RecipeBuilder()->withAuthor($author)->withName('zzz gâteau')->withServing(6)->withRecipeType(new RecipeTypeBuilder()->isDessert())
            ->addRow(new RecipeRowBuilder()->withIngredient(new IngredientBuilder()->withName('zzz sucre')->withImage('images/sucre.png'))->withUnit(new UnitBuilder()->isUnite())->withQuantity(2))
            ->addRow(new RecipeRowBuilder()->withIngredient(new IngredientBuilder()->withName('aaa farine')->withImage(''))->withUnit(new UnitBuilder()->isGramme())->withQuantity(250))
            ->build();
        $this->persister->saveRecipe($recipe);

        // When
        $result = $this->recipeLoader->get($recipe->getId());

        // Then
        $this->assertSame([$recipe->getId(), 'zzz gâteau', 6, 'dessert', 'dessert'], [$result->id, $result->name, $result->serving, $result->typeSlug, $result->typeName]);
        // L'ordre est celui de la saisie (colonne `position`), pas l'ordre alphabétique
        $this->assertEquals(
            [
                new RecipeRowDTO('zzz-sucre', 'zzz sucre', 'images/sucre.png', 2.0, 'unite', ''),
                new RecipeRowDTO('aaa-farine', 'aaa farine', '', 250.0, 'gramme', 'g'),
            ],
            $result->rows,
        );
    }

    public function testGetReturnsTheVersionOfTheRecipe(): void
    {
        // Given : une recette neuve (version 1) puis enregistrée une seconde fois (version 2)
        $recipe = new RecipeBuilder()->withAuthor($this->savedUser())->build();
        $this->persister->saveRecipe($recipe);
        $this->assertSame(1, $this->recipeLoader->get($recipe->getId())->version);
        $recipe->setName('zzz autre nom');
        static::getContainer()->get(RecipeRepository::class)->save($recipe);

        // When
        $result = $this->recipeLoader->get($recipe->getId());

        // Then
        $this->assertSame(2, $result->version);
    }

    public function testGetReturnsARecipeWithoutRow(): void
    {
        // Given
        $recipe = new RecipeBuilder()->withAuthor($this->savedUser())->build();
        $this->persister->saveRecipe($recipe);

        // When
        $result = $this->recipeLoader->get($recipe->getId());

        // Then
        $this->assertSame([], $result->rows);
    }

    public function testGetFailsWhenTheRecipeDoesNotExist(): void
    {
        // Then
        $this->expectExceptionObject(RecipeException::notFound('unknown-recipe'));

        // When
        $this->recipeLoader->get('unknown-recipe');
    }

    public function testListByAuthorAndTypeReturnsTheTypeAndTheRecipesOfTheAuthorSortedBySlug(): void
    {
        // Given
        $author = $this->savedUser();
        $second = $this->savedRecipe($author, 'zzz tarte', new RecipeTypeBuilder()->isDessert());
        $first = $this->savedRecipe($author, 'aaa gâteau', new RecipeTypeBuilder()->isDessert());
        $this->savedRecipe($author, 'mmm plat', new RecipeTypeBuilder()->isMeal());
        $this->savedRecipe($this->savedUser(), 'bbb dessert d\'un autre', new RecipeTypeBuilder()->isDessert());

        // When
        $result = $this->recipeLoader->listByAuthorAndType($author->getId(), 'dessert');

        // Then
        $this->assertEquals(new RecipeTypeDTO('dessert', 'dessert', new RecipeTypeBuilder()->isDessert()->build()->getImage(), 3), $result->recipeType);
        $this->assertEquals(
            [new RecipeSummaryDTO($first->getId(), 'aaa gâteau'), new RecipeSummaryDTO($second->getId(), 'zzz tarte')],
            $result->recipes,
        );
    }

    public function testListByAuthorAndTypeReturnsAnEmptyListForAKnownTypeWithoutRecipe(): void
    {
        // Given
        $author = $this->savedUser();
        $this->savedRecipe($author, 'mmm plat', new RecipeTypeBuilder()->isMeal());
        $this->persister->saveRecipeType(new RecipeTypeBuilder()->isDessert()->build());

        // When
        $result = $this->recipeLoader->listByAuthorAndType($author->getId(), 'dessert');

        // Then
        $this->assertSame('dessert', $result->recipeType->slug);
        $this->assertSame([], $result->recipes);
    }

    public function testListByAuthorAndTypeFailsForAnUnknownType(): void
    {
        // Then
        $this->expectExceptionObject(RecipeException::typeNotFound('unknown-type'));

        // When
        $this->recipeLoader->listByAuthorAndType($this->savedUser()->getId(), 'unknown-type');
    }

    public function testListOfOtherPeopleKeepsOriginalsNotYetCopiedSortedBySlug(): void
    {
        // Given
        $me = $this->savedUser();
        $other = $this->savedUser();
        $kept2 = $this->savedRecipe($other, 'zzz tarte', new RecipeTypeBuilder()->isDessert());
        $kept1 = $this->savedRecipe($other, 'aaa carbonara', new RecipeTypeBuilder()->isMeal());
        $alreadyCopied = $this->savedRecipe($other, 'bbb déjà copiée', new RecipeTypeBuilder()->isMeal());
        $this->savedRecipe($me, 'bbb déjà copiée', new RecipeTypeBuilder()->isMeal(), originalId: $alreadyCopied->getId());
        $this->savedRecipe($me, 'ccc ma recette', new RecipeTypeBuilder()->isMeal());
        $this->savedRecipe($other, 'ddd copie d\'un tiers', new RecipeTypeBuilder()->isMeal(), originalId: $kept1->getId());

        // When
        $result = $this->recipeLoader->listOfOtherPeople($me->getId());

        // Then
        $this->assertEquals(
            [new OtherPeopleRecipeDTO($kept1->getId(), 'aaa carbonara', 'plat'), new OtherPeopleRecipeDTO($kept2->getId(), 'zzz tarte', 'dessert')],
            $result,
        );
    }

    private function savedUser(): User
    {
        $user = new UserBuilder()->build();
        $this->persister->saveUser($user);

        return $user;
    }

    private function savedRecipe(User $author, string $name, RecipeTypeBuilder $type, ?string $originalId = null): Recipe
    {
        $recipe = new RecipeBuilder()->withAuthor($author)->withName($name)->withRecipeType($type)->withOriginalId($originalId)->build();
        $this->persister->saveRecipe($recipe);

        return $recipe;
    }
}
