<?php

namespace Repas\Repas\Infrastructure\DataFixture;


use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\ShoppingList as ShoppingListModel;
use Repas\Repas\Infrastructure\Entity\Recipe as RecipeEntity;
use Repas\Repas\Infrastructure\Entity\RecipeInShoppingList;
use Repas\Repas\Infrastructure\Entity\ShoppingList as ShoppingListEntity;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Infrastructure\Entity\User;

class ShoppingListFixture extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    private const array SHOPPING_LIST = [
        [
            'user' => 'alexiane.sichi@gmail.com',
            'date' => '2024-12-05T09:35:05+01:00',
            'locked' => true,
        ],
        [
            'user' => 'alexiane.sichi@gmail.com',
            'date' => '2024-12-22T19:31:05+01:00',
            'locked' => true,
        ],
        [
            'user' => 'alexiane.sichi@gmail.com',
            'date' => '2025-01-05T18:21:12+01:00',
            'locked' => true,
        ],
        [
            'user' => 'alexiane.sichi@gmail.com',
            'date' => '2025-01-20T10:56:23+01:00',
            'locked' => false,
        ],
    ];

    public function getDependencies(): array
    {
        return [RecipeFixture::class];
    }

    public static function getGroups(): array
    {
        return ['test', 'dev'];
    }

    public function load(ObjectManager $manager): void
    {
        foreach (self::SHOPPING_LIST as $shoppingList) {
            $userEntity = $this->getReference($shoppingList['user'], User::class);

            $shoppingListModel = ShoppingListModel::create(
                id: UuidGenerator::new(),
                owner: $userEntity->getModel(),
                date: DateTimeImmutable::createFromFormat(DATE_ATOM, $shoppingList['date']),
                locked: true,
                recipes: Tab::newEmpty(Recipe::class),
            );

            $shoppingListEntity = ShoppingListEntity::fromModel($shoppingListModel);
            $shoppingListEntity->setOwner($userEntity);

            //On ajoute entre 5 et 20 des recettes aléatoires
            $recipes = $this->getRandomRecipes(rand(5, 20));
            foreach ($recipes as $recipeEntity) {
                $recipeInShoppingListEntity = new RecipeInShoppingList(
                    id: UuidGenerator::new(),
                    shoppingList: $shoppingListEntity,
                    recipe: $recipeEntity,
                    serving: $recipeEntity->getServing(),
                );
                $manager->persist($recipeInShoppingListEntity);
                $shoppingListEntity->addRecipe($recipeInShoppingListEntity);
            }

            $manager->persist($shoppingListEntity);
        }

        $manager->flush();
    }

    /**
     * @return array<RecipeEntity>
     */
    private function getRandomRecipes(int $quantity): array
    {
        $res = [];
        for (;$quantity > 0; $quantity--) {
            $res[] = $this->getRandomRecipe();
        }
        return $res;
    }

    private function getRandomRecipe(): RecipeEntity
    {
        static $recipes = null;
        if (null === $recipes) {
            $recipes = RecipeFixture::getRecipesIds();
        }

        $recipeId = $recipes[array_rand($recipes)];
        return $this->getReference($recipeId, RecipeEntity::class);
    }
}
