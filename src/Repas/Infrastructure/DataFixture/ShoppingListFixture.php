<?php

namespace Repas\Repas\Infrastructure\DataFixture;


use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Repas\Repas\Infrastructure\Entity\Recipe as RecipeEntity;
use Repas\Repas\Infrastructure\Entity\Meal as MealEntity;
use Repas\Repas\Infrastructure\Entity\ShoppingList as ShoppingListEntity;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Infrastructure\Entity\User;

class ShoppingListFixture extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    private const array SHOPPING_LIST = [
        [
            'user' => 'alexiane.sichi@gmail.com',
            'createdAt' => '2024-12-05T09:35:05+01:00',
            'locked' => true,
        ],
        [
            'user' => 'alexiane.sichi@gmail.com',
            'createdAt' => '2024-12-22T19:31:05+01:00',
            'locked' => true,
        ],
        [
            'user' => 'alexiane.sichi@gmail.com',
            'createdAt' => '2025-01-05T18:21:12+01:00',
            'locked' => true,
        ],
        [
            'user' => 'alexiane.sichi@gmail.com',
            'createdAt' => '2025-01-20T10:56:23+01:00',
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

            $shoppingListEntity = new ShoppingListEntity(
                id: UuidGenerator::new(),
                ownerId: $userEntity->getId(),
                createdAt: DateTimeImmutable::createFromFormat(DATE_ATOM, $shoppingList['createdAt']),
                locked: $shoppingList['locked'],
            );

            $manager->persist($shoppingListEntity);

            // On ajoute entre 5 et 20 des recettes aléatoires (dans la quantité par défaut de la recette)
            $recipes = $this->getRandomRecipes($userEntity->getId(), rand(5, 20));
            foreach ($recipes as $recipeEntity) {
                $recipeInShoppingListEntity = new MealEntity(
                    id: UuidGenerator::new(),
                    shoppingListId: $shoppingListEntity->getId(),
                    recipeId: $recipeEntity->getId(),
                    serving: $recipeEntity->getServing(),
                );
                $manager->persist($recipeInShoppingListEntity);
            }
        }

        $manager->flush();
    }

    /**
     * @return array<RecipeEntity>
     */
    private function getRandomRecipes(string $ownerId, int $quantity): array
    {
        $res = [];
        for (;$quantity > 0; $quantity--) {
            $res[] = $this->getRandomRecipe($ownerId);
        }
        return $res;
    }

    private function getRandomRecipe(string $ownerId): RecipeEntity
    {
        static $recipes = null;
        if (null === $recipes) {
            $recipes = RecipeFixture::getRecipesIds($ownerId);
        }

        $recipeId = $recipes[array_rand($recipes)];
        return $this->getReference($recipeId, RecipeEntity::class);
    }
}
