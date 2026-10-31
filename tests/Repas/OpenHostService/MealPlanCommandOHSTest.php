<?php

namespace Repas\Tests\Repas\OpenHostService;

use LogicException;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\ChangeSpokenMealServings\ChangeSpokenMealServingsCommand;
use Repas\Repas\Application\Command\PlanSpokenMeal\PlanSpokenMealCommand;
use Repas\Repas\Application\Command\RemoveSpokenMeal\RemoveSpokenMealCommand;
use Repas\Repas\Application\OpenHostService\MealPlanCommandOHS;
use Repas\Repas\Domain\Exception\MealPlanningException;
use Repas\Repas\Domain\Model\MealPlanResult;
use Repas\Repas\Domain\Model\MealRemovalResult;
use Repas\Repas\Domain\Model\MealServingsResult;
use Repas\Tests\Helper\MockCommandBus;

/**
 * L'Open Host Service seulement (bus de commandes simulé) : commande construite, résultat renvoyé tel quel, erreur de domaine propagée. Le parcours avec
 * les vrais bus est dans `MealPlanCommandOHSFlowTest`.
 */
class MealPlanCommandOHSTest extends TestCase
{
    public function testItDispatchesThePlanningAndReturnsTheResultOfTheHandler(): void
    {
        // Given
        $result = new MealPlanResult('Gratin', 6, alreadyPlanned: false, listCreated: true, previousListSent: false);
        $bus = new MockCommandBus()->willReturn(PlanSpokenMealCommand::class, $result);

        // When
        $planned = new MealPlanCommandOHS($bus)->plan('user-id', 'recipe-1', 6);

        // Then
        $this->assertSame($result, $planned);
        $command = $bus->dispatched()[0];
        $this->assertInstanceOf(PlanSpokenMealCommand::class, $command);
        $this->assertSame(['user-id', 'recipe-1', 6], [$command->ownerId, $command->recipeId, $command->servings]);
        $this->assertNotSame('', $command->newShoppingListId);
    }

    public function testEachPlanningGetsItsOwnIdentifierForTheListItMayCreate(): void
    {
        // Given
        $bus = new MockCommandBus()->willReturn(PlanSpokenMealCommand::class, new MealPlanResult('Gratin', 4, false, false, false));
        $ohs = new MealPlanCommandOHS($bus);

        // When
        $ohs->plan('user-id', 'recipe-1');
        $ohs->plan('user-id', 'recipe-1');

        // Then
        $this->assertNotSame($bus->dispatched()[0]->newShoppingListId, $bus->dispatched()[1]->newShoppingListId);
    }

    public function testItDispatchesTheServingsChangeAndTheRemoval(): void
    {
        // Given
        $changed = MealServingsResult::changed('Gratin', 6);
        $removed = MealRemovalResult::lowered('Gratin', 2);
        $bus = new MockCommandBus()->willReturn(ChangeSpokenMealServingsCommand::class, $changed)->willReturn(RemoveSpokenMealCommand::class, $removed);
        $ohs = new MealPlanCommandOHS($bus);

        // When
        $changeResult = $ohs->changeServings('user-id', 'recipe-1', 6);
        $removeResult = $ohs->removeMeal('user-id', 'recipe-1', 2);

        // Then
        $this->assertSame($changed, $changeResult);
        $this->assertSame($removed, $removeResult);
        $this->assertEquals([new ChangeSpokenMealServingsCommand('user-id', 'recipe-1', 6), new RemoveSpokenMealCommand('user-id', 'recipe-1', 2)], $bus->dispatched());
    }

    public function testAKnownFailureOfTheHandlerIsNotHidden(): void
    {
        // Given
        $bus = new MockCommandBus()->willThrow(PlanSpokenMealCommand::class, MealPlanningException::missingConversion('Gratin', 'Farine'));

        // Then
        $this->expectException(MealPlanningException::class);

        // When
        new MealPlanCommandOHS($bus)->plan('user-id', 'recipe-1');
    }

    public function testAHandlerThatReturnsNothingIsABug(): void
    {
        // Given
        $ohs = new MealPlanCommandOHS(new MockCommandBus());

        // Then
        $this->expectException(LogicException::class);

        // When
        $ohs->plan('user-id', 'recipe-1');
    }

    public function testAChangeThatReturnsNothingIsABug(): void
    {
        $this->expectException(LogicException::class);

        new MealPlanCommandOHS(new MockCommandBus())->changeServings('user-id', 'recipe-1', 6);
    }

    public function testARemovalThatReturnsNothingIsABug(): void
    {
        $this->expectException(LogicException::class);

        new MealPlanCommandOHS(new MockCommandBus())->removeMeal('user-id', 'recipe-1');
    }
}
