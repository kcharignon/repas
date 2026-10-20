<?php

namespace Repas\Tests\Command;


use PHPUnit\Framework\TestCase;
use Repas\Command\UpdateUserStatisticsCommand;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\RecipeInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UserInMemoryRepository;
use Symfony\Component\Console\Tester\CommandTester;

class UpdateUserStatisticsCommandTest extends TestCase
{
    public function testTheIngredientsCountedAreTheOnesTheUserCreatedNotTheAdoptedCommons(): void
    {
        // Given : deux ingrédients créés par l'utilisateur, deux copies d'adoption de communs, et l'ingrédient d'un autre
        $user = new UserBuilder()->withId('user-id')->withEmail('user@example.com')->build();
        $other = new UserBuilder()->withId('other-id')->withEmail('other@example.com')->build();
        $users = new UserInMemoryRepository([$user, $other]);
        $ingredients = new IngredientInMemoryRepository([
            new IngredientBuilder()->isFlour()->withCreator($user, true)->build(),
            new IngredientBuilder()->isMilk()->withCreator($user, true)->build(),
            new IngredientBuilder()->isButter()->withCreator($user, true)->withAdoptedFrom('beurre')->build(),
            new IngredientBuilder()->isSugar()->withCreator($user, true)->withAdoptedFrom('sucre')->build(),
            new IngredientBuilder()->isPasta()->withCreator($other, true)->build(),
            new IngredientBuilder()->isEgg()->build(),
        ]);
        $tester = new CommandTester(new UpdateUserStatisticsCommand($users, $ingredients, new RecipeInMemoryRepository([
            new RecipeBuilder()->isPastaCarbonara()->withAuthor($user)->build(),
        ])));

        // When
        $status = $tester->execute([]);

        // Then : une adoption n'est pas une création (ADR 0028 décision 7)
        $this->assertSame(0, $status);
        $this->assertSame(2, $users->findOneById('user-id')->getStatistics()['ingredients']);
        $this->assertSame(1, $users->findOneById('user-id')->getStatistics()['recipes']);
        $this->assertSame(1, $users->findOneById('other-id')->getStatistics()['ingredients']);
    }
}
