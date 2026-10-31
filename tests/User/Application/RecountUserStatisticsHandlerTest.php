<?php

namespace Repas\Tests\User\Application;

use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\OpenHostService\CreatedContentCommandOHS;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\RecipeInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UserInMemoryRepository;
use Repas\User\Application\RecountUserStatistics\RecountedStatistics;
use Repas\User\Application\RecountUserStatistics\RecountUserStatisticsCommand;
use Repas\User\Application\RecountUserStatistics\RecountUserStatisticsHandler;
use Repas\User\Infrastructure\Acl\CreatedContentCommandAdapter;

/**
 * Le vrai adapter et le vrai Open Host Service de `Repas` sur les doubles en mémoire (ADR 0035).
 */
class RecountUserStatisticsHandlerTest extends TestCase
{
    public function testTheIngredientsCountedAreTheOnesTheUserCreatedNotTheCopiedOnes(): void
    {
        // Given : deux ingrédients créés par l'utilisateur, deux copies (reçues du catalogue ou faites avec une recette), et l'ingrédient d'un autre
        $user = new UserBuilder()->withId('user-id')->withEmail('user@example.com')->build();
        $other = new UserBuilder()->withId('other-id')->withEmail('other@example.com')->build();
        $users = new UserInMemoryRepository([$user, $other]);
        $ingredients = new IngredientInMemoryRepository([
            new IngredientBuilder()->isFlour()->withCreator($user, true)->build(),
            new IngredientBuilder()->isMilk()->withCreator($user, true)->build(),
            new IngredientBuilder()->isButter()->withCreator($user, true)->withAdoptedFrom('beurre')->build(),
            new IngredientBuilder()->isSugar()->withCreator($user, true)->withAdoptedFrom('sucre')->build(),
            new IngredientBuilder()->isPasta()->withCreator($other, true)->build(),
            new IngredientBuilder()->isEgg()->withCreator(new UserBuilder()->withId('third-id')->build(), true)->build(),
        ]);
        $recipes = new RecipeInMemoryRepository([new RecipeBuilder()->isPastaCarbonara()->withAuthor($user)->build()]);
        $handler = new RecountUserStatisticsHandler($users, new CreatedContentCommandAdapter(new CreatedContentCommandOHS($ingredients, $recipes)));

        // When
        $recounted = $handler(new RecountUserStatisticsCommand('user-id'));

        // Then : une copie n'est pas une création (ADR 0028 décision 7 et D7), et seul le compte demandé change
        $this->assertEquals(new RecountedStatistics(2, 1), $recounted);
        $this->assertSame(2, $users->findOneById('user-id')->getStatistics()['ingredients']);
        $this->assertSame(1, $users->findOneById('user-id')->getStatistics()['recipes']);
        $this->assertSame(0, $users->findOneById('other-id')->getStatistics()['ingredients'] ?? 0);
    }
}
