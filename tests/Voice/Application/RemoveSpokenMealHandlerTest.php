<?php

namespace Repas\Tests\Voice\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Shared\Application\Dto\ListedMeal;
use Repas\Shared\Application\Dto\RemovedSpokenMeal;
use Repas\Shared\Application\Dto\SpokenMealLookupResult;
use Repas\Tests\Helper\SpokenListLookupInMemory;
use Repas\Tests\Helper\VoiceMealPlannerInMemory;
use Repas\Voice\Application\Command\AddSpokenItem\AddSpokenItemResult;
use Repas\Voice\Application\Command\RemoveSpokenMeal\RemoveSpokenMealCommand;
use Repas\Voice\Application\Command\RemoveSpokenMeal\RemoveSpokenMealHandler;
use Repas\Voice\Domain\Model\DialogueOption;
use Repas\Voice\Domain\Model\DialogueStep;
use Repas\Voice\Domain\Service\SpokenIngredientNamer;

/**
 * Retirer le repas que l'utilisateur vient de dire (ADR 0029 décision 12, VOX-306), avec les deux ports de `Shared` en mémoire : aucune base, aucun
 * noyau. La recherche parmi les repas planifiés et le retrait se prouvent dans `Repas` (`SpokenListLookupAdapterTest`, `VoiceMealPlannerFlowTest`) et de
 * bout en bout (`AlexaWebhookApiFlowTest`).
 */
class RemoveSpokenMealHandlerTest extends TestCase
{
    private SpokenListLookupInMemory $lookup;
    private VoiceMealPlannerInMemory $planner;
    private RemoveSpokenMealHandler $handler;

    protected function setUp(): void
    {
        $this->lookup = new SpokenListLookupInMemory();
        $this->planner = new VoiceMealPlannerInMemory();
        $this->handler = new RemoveSpokenMealHandler($this->lookup, $this->planner, new SpokenIngredientNamer());
    }

    private static function hachis(int $servings = 4): ListedMeal
    {
        return new ListedMeal('recipe-1', 'Hachis parmentier', $servings);
    }

    public function testTheWholeMealLeavesTheListAndItIsSaidShortly(): void
    {
        // Given
        $this->lookup->willFindMeal('hachis parmentier', SpokenMealLookupResult::found(self::hachis()));
        $this->planner->willRemove(RemovedSpokenMeal::removed('Hachis parmentier'));

        // When
        $result = ($this->handler)(new RemoveSpokenMealCommand('user-1', 'le hachis parmentier', sessionIsOpen: false));

        // Then : cherché sous son nom sans article parmi les repas de la liste, retiré en entier (aucun nombre), la séance se ferme
        $this->assertSame([['user-1', 'hachis parmentier']], $this->lookup->askedMeals);
        $this->assertSame([['user-1', 'recipe-1', null]], $this->planner->removed);
        $this->assertEquals(new AddSpokenItemResult("C'est retiré : le repas hachis parmentier.", keepSessionOpen: false, added: false), $result);
    }

    public function testTheServingsSaidAreTakenOffAndTheNewTotalIsSaid(): void
    {
        // Given : le repas est prévu pour 4, on enlève 2 personnes
        $this->lookup->willFindMeal('hachis parmentier', SpokenMealLookupResult::found(self::hachis(4)));
        $this->planner->willRemove(RemovedSpokenMeal::lowered('Hachis parmentier', 2));

        // When
        $result = ($this->handler)(new RemoveSpokenMealCommand('user-1', 'hachis parmentier', sessionIsOpen: true, servings: 2));

        // Then : 2 personnes demandées au port, qui garde 2 ; la séance reste ouverte sans relance
        $this->assertSame([['user-1', 'recipe-1', 2]], $this->planner->removed);
        $this->assertEquals(new AddSpokenItemResult("C'est changé : hachis parmentier, pour 2 personnes.", keepSessionOpen: true, added: false), $result);
        $this->assertNull($result->reprompt, 'jamais de relance');
    }

    public function testTheMealThatLeftTheListBetweenTheReadingAndTheWritingIsSaidAndNothingElseHappens(): void
    {
        // Given
        $this->lookup->willFindMeal('hachis parmentier', SpokenMealLookupResult::found(self::hachis()));
        $this->planner->willRemove(RemovedSpokenMeal::notPlanned('Hachis parmentier'));

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
        $this->assertSame([], $this->planner->removed);
        $this->assertSame([], $this->planner->planned);
    }

    public function testAListSentToTheDriveIsSaidAndNeverTouched(): void
    {
        // Given
        $this->lookup->willFindMeal('hachis parmentier', SpokenMealLookupResult::sentList());

        // When
        $result = ($this->handler)(new RemoveSpokenMealCommand('user-1', 'hachis parmentier', sessionIsOpen: false));

        // Then
        $this->assertSame('Ta liste est déjà partie au drive.', $result->speech);
        $this->assertSame([], $this->planner->removed);
    }

    public function testAMealThatIsNotInTheListProposesTheClosestPlannedMealsAndTheOtherType(): void
    {
        // Given : la liste porte deux gratins, la phrase n'en désigne aucun sûrement
        $this->lookup->willFindMeal('gratin', SpokenMealLookupResult::notPlanned([new ListedMeal('recipe-2', 'Gratin dauphinois', 4), new ListedMeal('recipe-3', 'Gratin de pâtes', 2)]));

        // When
        $result = ($this->handler)(new RemoveSpokenMealCommand('user-1', 'gratin', sessionIsOpen: false, servings: 2, originalPhrase: 'le repas gratin'));

        // Then : rien n'est retiré, une question, la séance reste ouverte avec son reprompt
        $this->assertSame([], $this->planner->removed);
        $this->assertSame('Je ne trouve pas le repas gratin dans ta liste. Tu veux dire gratin dauphinois ou gratin de pâtes ? Ou un produit ?', $result->speech);
        $this->assertTrue($result->keepSessionOpen);
        $this->assertSame('Tu veux dire gratin dauphinois ou gratin de pâtes ? Ou un produit ?', $result->reprompt);
        $state = $result->nextState;
        $this->assertNotNull($state);
        $this->assertTrue($state->isRemovalProposal());
        $this->assertTrue($state->isMeal());
        $this->assertSame(DialogueStep::CHOOSE, $state->step);
        $this->assertEquals([new DialogueOption('recipe-2', 'Gratin dauphinois'), new DialogueOption('recipe-3', 'Gratin de pâtes')], $state->options);
        $this->assertSame([2, false, 'le repas gratin'], [$state->servings, $state->otherSideTried, $state->originalPhrase]);
        $this->assertNull($state->rememberablePhrase(), 'retirer ne retient aucune phrase');
    }

    public function testAMealThatLooksLikeNothingAsksOnlyIfItIsAProduct(): void
    {
        // Given : la liste ne porte aucun repas qui ressemble à la phrase
        $this->lookup->willFindMeal('lasagnes', SpokenMealLookupResult::notPlanned());

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
        $this->lookup->willFindMeal('lasagnes', SpokenMealLookupResult::notPlanned());

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
        $this->lookup->willFindMeal('gratin', SpokenMealLookupResult::notPlanned([new ListedMeal('recipe-2', 'Gratin dauphinois', 4)]));

        // When
        $result = ($this->handler)(new RemoveSpokenMealCommand('user-1', 'gratin', sessionIsOpen: false, otherSideTried: true));

        // Then : la proposition, sans « ou un produit ? »
        $this->assertSame('Je ne trouve pas le repas gratin dans ta liste. Tu veux dire gratin dauphinois ?', $result->speech);
        $this->assertTrue($result->nextState?->otherSideTried);
    }

    public function testAPhraseWithoutAnyWordThatCountsGetsTheExampleAndNothingIsRead(): void
    {
        // When
        $result = ($this->handler)(new RemoveSpokenMealCommand('user-1', '?!', sessionIsOpen: true));

        // Then
        $this->assertSame("Je n'ai pas compris. Dis par exemple : enlève le repas hachis parmentier.", $result->speech);
        $this->assertTrue($result->keepSessionOpen);
        $this->assertSame([], $this->lookup->askedMeals);
        $this->assertSame([], $this->planner->removed);
    }

    public function testAMealThatBelongsToAnotherUserFailsTheWholeCommand(): void
    {
        // Given : un état forgé a glissé la recette d'un autre ; le port la refuse (404) et rien n'est écrit
        $this->planner->willThrow(RecipeException::notFound('recipe-9'));

        // Then : l'exception n'est pas attrapée (le bus annule la commande et le webhook répond la phrase d'échec)
        $this->expectExceptionObject(RecipeException::notFound('recipe-9'));

        // When
        $this->handler->remove('user-1', new DialogueOption('recipe-9', 'Hachis'), null, keepSessionOpen: false);
    }

    public function testAChosenProposalIsRemovedAndTheSessionIsClosed(): void
    {
        // Given
        $this->planner->willRemove(RemovedSpokenMeal::removed('Gratin dauphinois'));

        // When
        $result = $this->handler->remove('user-1', new DialogueOption('recipe-2', 'Gratin dauphinois'), null, keepSessionOpen: false);

        // Then
        $this->assertSame([['user-1', 'recipe-2', null]], $this->planner->removed);
        $this->assertEquals(new AddSpokenItemResult("C'est retiré : le repas gratin dauphinois.", keepSessionOpen: false, added: false), $result);
    }
}
