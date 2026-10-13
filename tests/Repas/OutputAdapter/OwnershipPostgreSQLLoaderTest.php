<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\MealException;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Shared\Application\Interface\OwnershipLoader;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;

class OwnershipPostgreSQLLoaderTest extends DatabaseTestCase
{
    private OwnershipLoader $ownershipLoader;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ownershipLoader = static::getContainer()->get(OwnershipLoader::class);
        $this->persister = new DataPersister(static::getContainer());
    }

    public function testIngredientCreatorIsTheCreatorOrNullForACommonIngredient(): void
    {
        // Given
        $creator = new UserBuilder()->build();
        $own = new IngredientBuilder()->withName('zzz perso')->withCreator($creator, true)->build();
        $common = new IngredientBuilder()->withName('zzz commun')->build();
        $this->persister->saveIngredient($own);
        $this->persister->saveIngredient($common);

        // When & Then
        $this->assertSame($creator->getId(), $this->ownershipLoader->ingredientCreatorId($own->getSlug()));
        $this->assertNull($this->ownershipLoader->ingredientCreatorId($common->getSlug()));
    }

    public function testIngredientCreatorFailsForAnUnknownIngredient(): void
    {
        $this->expectExceptionObject(IngredientException::notFound('inconnu'));

        $this->ownershipLoader->ingredientCreatorId('inconnu');
    }

    public function testShoppingListOwnerAndMealOwnerAreTheOwnerOfTheList(): void
    {
        // Given
        $owner = new UserBuilder()->build();
        $this->persister->saveUser($owner);
        $recipe = new RecipeBuilder()->withAuthor($owner)->build();
        $this->persister->saveRecipe($recipe);
        $shoppingList = new ShoppingListBuilder()->withOwner($owner)->addRecipe($recipe)->build();
        static::getContainer()->get(ShoppingListRepository::class)->save($shoppingList);
        $mealId = $shoppingList->getMeals()->reset()->getId();

        // When & Then
        $this->assertSame($owner->getId(), $this->ownershipLoader->shoppingListOwnerId($shoppingList->getId()));
        $this->assertSame($owner->getId(), $this->ownershipLoader->mealOwnerId($mealId));
        $this->assertSame($owner->getId(), $this->ownershipLoader->recipeAuthorId($recipe->getId()));
    }

    public function testAnUnknownShoppingListMealOrRecipeRaisesItsNotFoundException(): void
    {
        $this->assertThrowsObject(ShoppingListException::shoppingListNotFound('inconnue'), fn() => $this->ownershipLoader->shoppingListOwnerId('inconnue'));
        $this->assertThrowsObject(MealException::notFound(), fn() => $this->ownershipLoader->mealOwnerId('inconnu'));
        $this->assertThrowsObject(RecipeException::notFound('inconnue'), fn() => $this->ownershipLoader->recipeAuthorId('inconnue'));
    }

    private function assertThrowsObject(\Throwable $expected, callable $action): void
    {
        try {
            $action();
        } catch (\Throwable $actual) {
            $this->assertEquals($expected, $actual);

            return;
        }

        $this->fail(sprintf('%s attendue.', $expected::class));
    }
}
