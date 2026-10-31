<?php

namespace Repas\Tests\Voice\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Tests\Helper\RepasForVoice;
use Repas\Voice\Application\Command\AddSpokenItem\AddSpokenItemResult;
use Repas\Voice\Application\Command\RemoveSpokenMeal\RemoveSpokenMealCommand;
use Repas\Voice\Application\Command\RemoveSpokenMeal\RemoveSpokenMealHandler;
use Repas\Voice\Domain\Model\DialogueOption;
use Repas\Voice\Domain\Model\DialogueStep;
use Repas\Voice\Domain\Model\ListedMeal;
use Repas\Voice\Domain\Model\RemovedSpokenMeal;
use Repas\Voice\Domain\Service\SpokenIngredientNamer;

/**
 * Retirer le repas que l'utilisateur vient de dire (ADR 0029 décision 12, VOX-306), avec les vrais adapters du contexte et les vrais Open Host Services de `Repas` sur ses doubles en mémoire (`RepasForVoice`), et le bus de commandes simulé :
 * aucune base, aucun noyau. La recherche parmi les repas planifiés et le retrait se prouvent dans `Repas`
 * (`ActiveListLookupCommandOHSTest`, `MealPlanCommandOHSFlowTest`) et de bout en bout (`AlexaWebhookApiFlowTest`).
 */
class RemoveSpokenMealHandlerTest extends TestCase
{
    private RepasForVoice $repas;
    private RemoveSpokenMealHandler $handler;

    protected function setUp(): void
    {
        $this->repas = new RepasForVoice();
        $this->handler = new RemoveSpokenMealHandler($this->repas->mealLookupPort(), $this->repas->mealPlanPort(), new SpokenIngredientNamer());
    }

    private static function hachis(int $servings = 4): ListedMeal
    {
        return new ListedMeal('recipe-1', 'Hachis parmentier', $servings);
    }

    public function testTheWholeMealLeavesTheListAndItIsSaidShortly(): void
    {
        // Given
        $this->repas->listPlans(self::hachis());
        $this->repas->willRemoveMeal(RemovedSpokenMeal::removed('Hachis parmentier'));

        // When
        $result = ($this->handler)(new RemoveSpokenMealCommand('user-1', 'le hachis parmentier', sessionIsOpen: false));

        // Then : cherché sous son nom sans article parmi les repas de la liste, retiré en entier (aucun nombre), la séance se ferme
        $this->assertSame([['user-1', 'hachis parmentier']], $this->repas->lookedUpMeals);
        $this->assertSame([['user-1', 'recipe-1', null]], $this->repas->mealsRemoved());
        $this->assertEquals(new AddSpokenItemResult("C'est retiré : le repas hachis parmentier.", keepSessionOpen: false, added: false), $result);
    }

    public function testTheServingsSaidAreTakenOffAndTheNewTotalIsSaid(): void
    {
        // Given : le repas est prévu pour 4, on enlève 2 personnes
        $this->repas->listPlans(self::hachis(4));
        $this->repas->willRemoveMeal(RemovedSpokenMeal::lowered('Hachis parmentier', 2));

        // When
        $result = ($this->handler)(new RemoveSpokenMealCommand('user-1', 'hachis parmentier', sessionIsOpen: true, servings: 2));

        // Then : 2 personnes demandées au port, qui garde 2 ; la séance reste ouverte sans relance
        $this->assertSame([['user-1', 'recipe-1', 2]], $this->repas->mealsRemoved());
        $this->assertEquals(new AddSpokenItemResult("C'est changé : hachis parmentier, pour 2 personnes.", keepSessionOpen: true, added: false), $result);
        $this->assertNull($result->reprompt, 'jamais de relance');
    }

    public function testTheMealThatLeftTheListBetweenTheReadingAndTheWritingIsSaidAndNothingElseHappens(): void
    {
        // Given
        $this->repas->listPlans(self::hachis());
        $this->repas->willRemoveMeal(RemovedSpokenMeal::notPlanned('Hachis parmentier'));

        // When
        $result = ($this->handler)(new RemoveSpokenMealCommand('user-1', 'hachis parmentier', sessionIsOpen: false));

        // Then
        $this->assertSame("Ce repas n'est plus dans ta liste.", $result->speech);
        $this->assertNull($result->nextState);
    }

    public function testWithoutAnActiveListNothingIsRemovedOrCreated(): void
    {
        // Given : la recherche ne trouve aucune liste active (par défaut)

        // When
        $result = ($this->handler)(new RemoveSpokenMealCommand('user-1', 'hachis parmentier', sessionIsOpen: false));

        // Then
        $this->assertSame("Tu n'as pas de liste en cours.", $result->speech);
        $this->assertSame([], $this->repas->mealsRemoved());
        $this->assertSame([], $this->repas->mealsPlanned());
    }

    public function testAListSentToTheDriveIsSaidAndNeverTouched(): void
    {
        // Given
        $this->repas->hasSentList();

        // When
        $result = ($this->handler)(new RemoveSpokenMealCommand('user-1', 'hachis parmentier', sessionIsOpen: false));

        // Then
        $this->assertSame('Ta liste est déjà partie au drive.', $result->speech);
        $this->assertSame([], $this->repas->mealsRemoved());
    }

    public function testAMealThatIsNotInTheListProposesTheClosestPlannedMealsAndTheOtherType(): void
    {
        // Given : la liste porte deux gratins, la phrase n'en désigne aucun sûrement
        $this->repas->listPlans(new ListedMeal('recipe-2', 'Gratin dauphinois', 4))->listPlans(new ListedMeal('recipe-3', 'Gratin de pâtes', 2));

        // When
        $result = ($this->handler)(new RemoveSpokenMealCommand('user-1', 'gratin', sessionIsOpen: false, servings: 2, originalPhrase: 'le repas gratin'));

        // Then : rien n'est retiré, une question, la séance reste ouverte avec son reprompt
        $this->assertSame([], $this->repas->mealsRemoved());
        $this->assertSame('Je ne trouve pas le repas gratin dans ta liste. Tu veux dire gratin de pâtes ou gratin dauphinois ? Ou un produit ?', $result->speech);
        $this->assertTrue($result->keepSessionOpen);
        $this->assertSame('Tu veux dire gratin de pâtes ou gratin dauphinois ? Ou un produit ?', $result->reprompt);
        $state = $result->nextState;
        $this->assertNotNull($state);
        $this->assertTrue($state->isRemovalProposal());
        $this->assertTrue($state->isMeal());
        $this->assertSame(DialogueStep::CHOOSE, $state->step);
        $this->assertEquals([new DialogueOption('recipe-3', 'Gratin de pâtes'), new DialogueOption('recipe-2', 'Gratin dauphinois')], $state->options);
        $this->assertSame([2, false, 'le repas gratin'], [$state->servings, $state->otherSideTried, $state->originalPhrase]);
        $this->assertNull($state->rememberablePhrase(), 'retirer ne retient aucune phrase');
    }

    public function testAMealThatLooksLikeNothingAsksOnlyIfItIsAProduct(): void
    {
        // Given : la liste ne porte aucun repas qui ressemble à la phrase
        $this->repas->hasActiveList();

        // When
        $result = ($this->handler)(new RemoveSpokenMealCommand('user-1', 'lasagnes', sessionIsOpen: false));

        // Then
        $this->assertSame("Je ne trouve pas le repas lasagnes dans ta liste. C'est un produit ?", $result->speech);
        $this->assertNotNull($result->nextState);
        $this->assertSame([], $result->nextState->options);
        $this->assertFalse($result->nextState->otherSideTried);
    }

    public function testOnceTheProductsHaveBeenSearchedNothingMoreIsOffered(): void
    {
        // Given : l'utilisateur a répondu « un repas » à une question de produit, et rien ne ressemble à la phrase
        $this->repas->hasActiveList();

        // When
        $result = ($this->handler)(new RemoveSpokenMealCommand('user-1', 'lasagnes', sessionIsOpen: false, otherSideTried: true));

        // Then : une affirmation seule, la séance se ferme, plus d'état
        $this->assertSame("Je ne trouve rien qui s'appelle lasagnes dans ta liste.", $result->speech);
        $this->assertFalse($result->keepSessionOpen);
        $this->assertNull($result->nextState);
    }

    public function testOnceTheProductsHaveBeenSearchedTheProposalsHaveNoOtherWayOut(): void
    {
        // Given
        $this->repas->listPlans(new ListedMeal('recipe-2', 'Gratin dauphinois', 4));

        // When
        $result = ($this->handler)(new RemoveSpokenMealCommand('user-1', 'gratin du chef', sessionIsOpen: false, otherSideTried: true));

        // Then : la proposition, sans « ou un produit ? »
        $this->assertSame('Je ne trouve pas le repas gratin du chef dans ta liste. Tu veux dire gratin dauphinois ?', $result->speech);
        $this->assertTrue($result->nextState?->otherSideTried);
    }

    public function testAPhraseWithoutAnyWordThatCountsGetsTheExampleAndNothingIsRead(): void
    {
        // When
        $result = ($this->handler)(new RemoveSpokenMealCommand('user-1', '?!', sessionIsOpen: true));

        // Then
        $this->assertSame("Je n'ai pas compris. Dis par exemple : enlève le repas hachis parmentier.", $result->speech);
        $this->assertTrue($result->keepSessionOpen);
        $this->assertSame([], $this->repas->lookedUpMeals);
        $this->assertSame([], $this->repas->mealsRemoved());
    }

    public function testAMealThatBelongsToAnotherUserFailsTheWholeCommand(): void
    {
        // Given : un état forgé a glissé la recette d'un autre ; le port la refuse (404) et rien n'est écrit
        $this->repas->willFailToWriteMeals(RecipeException::notFound('recipe-9'));

        // Then : l'exception n'est pas attrapée (le bus annule la commande et le webhook répond la phrase d'échec)
        $this->expectExceptionObject(RecipeException::notFound('recipe-9'));

        // When
        $this->handler->remove('user-1', new DialogueOption('recipe-9', 'Hachis'), null, keepSessionOpen: false);
    }

    public function testAChosenProposalIsRemovedAndTheSessionIsClosed(): void
    {
        // Given
        $this->repas->willRemoveMeal(RemovedSpokenMeal::removed('Gratin dauphinois'));

        // When
        $result = $this->handler->remove('user-1', new DialogueOption('recipe-2', 'Gratin dauphinois'), null, keepSessionOpen: false);

        // Then
        $this->assertSame([['user-1', 'recipe-2', null]], $this->repas->mealsRemoved());
        $this->assertEquals(new AddSpokenItemResult("C'est retiré : le repas gratin dauphinois.", keepSessionOpen: false, added: false), $result);
    }

    public function testTwoMealsWithTheSameNameAreToldApartByTheirServingsAndNeverGuessed(): void
    {
        // Given : deux recettes de même nom planifiées, pour 4 et pour 2 personnes
        $this->repas->listPlans(new ListedMeal('recipe-1', 'Hachis parmentier', 4))->listPlans(new ListedMeal('recipe-2', 'Hachis parmentier', 2));

        // When
        $result = ($this->handler)(new RemoveSpokenMealCommand('user-1', 'le hachis parmentier', sessionIsOpen: false));

        // Then : rien n'est retiré ; la question distingue les deux par le nombre de personnes et ne dit pas « je ne trouve pas » (ils y sont)
        $this->assertSame([], $this->repas->mealsRemoved());
        $this->assertSame('Tu veux dire hachis parmentier pour 4 ou hachis parmentier pour 2 ? Ou un produit ?', $result->speech);
        $this->assertTrue($result->keepSessionOpen);
        $state = $result->nextState;
        $this->assertNotNull($state);
        $this->assertEquals([new DialogueOption('recipe-1', 'Hachis parmentier pour 4'), new DialogueOption('recipe-2', 'Hachis parmentier pour 2')], $state->options);
        $this->assertSame('Tu veux dire hachis parmentier pour 4 ou hachis parmentier pour 2 ? Ou un produit ?', $result->reprompt);
    }

    public function testHomonymsWithTheSameServingsAreOnlyAskedOnce(): void
    {
        // Given : deux repas de même nom ET de même nombre de personnes : la voix ne les distingue pas, un seul est proposé
        $this->repas->listPlans(new ListedMeal('recipe-1', 'Hachis parmentier', 4))->listPlans(new ListedMeal('recipe-2', 'Hachis parmentier', 4));

        // When
        $result = ($this->handler)(new RemoveSpokenMealCommand('user-1', 'hachis parmentier', sessionIsOpen: false));

        // Then
        $this->assertCount(1, $result->nextState->options);
        $this->assertSame('Tu veux dire hachis parmentier pour 4 ? Ou un produit ?', $result->speech);
    }

    public function testOnlyTheMealsThatShareANameGetTheirServingsAddedToTheirLabel(): void
    {
        // Given : deux hachis homonymes et un autre repas
        $this->repas->listPlans(new ListedMeal('recipe-1', 'Hachis parmentier', 4))->listPlans(new ListedMeal('recipe-2', 'Hachis parmentier', 2))->listPlans(new ListedMeal('recipe-3', 'Hachis de boeuf', 6));

        // When
        $result = ($this->handler)(new RemoveSpokenMealCommand('user-1', 'hachis maison', sessionIsOpen: false));

        // Then : l'autre repas garde son nom, les homonymes sont distingués
        $this->assertSame(
            ['Hachis parmentier pour 4', 'Hachis parmentier pour 2', 'Hachis de boeuf'],
            array_map(static fn(DialogueOption $option): string => $option->name, $result->nextState->options),
        );
        $this->assertStringStartsWith('Je ne trouve pas le repas hachis maison dans ta liste.', $result->speech);
    }
}
