<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\MealException;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Exception\ShoppingListRowException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Application\Loader\OwnershipLoader;
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

    public function testIngredientCreatorIsTheOwnerOfTheIngredient(): void
    {
        // Given
        $creator = new UserBuilder()->build();
        $own = new IngredientBuilder()->withName('zzz perso')->withCreator($creator, true)->build();
        $another = new IngredientBuilder()->withName('zzz autre')->withCreator(new UserBuilder()->build(), true)->build();
        $this->persister->saveIngredient($own);
        $this->persister->saveIngredient($another);

        // When & Then
        $this->assertSame($creator->getId(), $this->ownershipLoader->ingredientCreatorId($own->getSlug()));
        $this->assertSame($another->getCreatorId(), $this->ownershipLoader->ingredientCreatorId($another->getSlug()));
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
        $this->assertSame($owner->getId(), $this->ownershipLoader->recipeAuthorIdFor($recipe->getId(), $owner->getId()));
        // Une recette ordinaire donne son auteur à tout utilisateur : le voter refuse alors (403) celui qui n'en est pas l'auteur
        $this->assertSame($owner->getId(), $this->ownershipLoader->recipeAuthorIdFor($recipe->getId(), 'un-autre'));
    }

    public function testAnUnknownShoppingListMealOrRecipeRaisesItsNotFoundException(): void
    {
        $this->assertThrowsObject(ShoppingListException::shoppingListNotFound('inconnue'), fn() => $this->ownershipLoader->shoppingListOwnerId('inconnue'));
        $this->assertThrowsObject(MealException::notFound(), fn() => $this->ownershipLoader->mealOwnerId('inconnu'));
        $this->assertThrowsObject(RecipeException::notFound('inconnue'), fn() => $this->ownershipLoader->recipeAuthorIdFor('inconnue', 'un-utilisateur'));
    }

    public function testASecretRecipeIsKnownToItsAuthorOnlyAndIsNotFoundForAnyoneElseLikeAnUnknownOne(): void
    {
        // Given : une recette secrète (ADR 0033)
        $author = new UserBuilder()->build();
        $this->persister->saveUser($author);
        $recipe = new RecipeBuilder()->withAuthor($author)->asSecret()->build();
        $this->persister->saveRecipe($recipe);

        // Then : son auteur la retrouve ; tout autre reçoit EXACTEMENT l'exception d'une recette inconnue (même 404, même message)
        $this->assertSame($author->getId(), $this->ownershipLoader->recipeAuthorIdFor($recipe->getId(), $author->getId()));
        $this->assertThrowsObject(RecipeException::notFound($recipe->getId()), fn() => $this->ownershipLoader->recipeAuthorIdFor($recipe->getId(), 'un-autre'));
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

    public function testShoppingListRowOwnerIsTheOwnerOfItsList(): void
    {
        // Given
        $owner = new UserBuilder()->build();
        $this->persister->saveUser($owner);
        $ingredient = new IngredientBuilder()->isEgg()->build();
        $this->persister->saveIngredient($ingredient);
        $shoppingList = new ShoppingListBuilder()->withOwner($owner)->addIngredient($ingredient)->build();
        static::getContainer()->get(ShoppingListRepository::class)->save($shoppingList);

        // When & Then
        $this->assertSame($owner->getId(), $this->ownershipLoader->shoppingListRowOwnerId($shoppingList->getRows()->reset()->getId()));
        $this->assertThrowsObject(ShoppingListRowException::notFound('inconnue'), fn() => $this->ownershipLoader->shoppingListRowOwnerId('inconnue'));
    }
}
