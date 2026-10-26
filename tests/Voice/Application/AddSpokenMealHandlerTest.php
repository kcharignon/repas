<?php

namespace Repas\Tests\Voice\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Shared\Application\Dto\ChangedMealServings;
use Repas\Shared\Application\Dto\PlannedSpokenMeal;
use Repas\Shared\Application\Dto\RecipeSuggestion;
use Repas\Shared\Application\Dto\ResolvedRecipe;
use Repas\Tests\Helper\SpokenRecipeResolverInMemory;
use Repas\Tests\Helper\VoiceMealPlannerInMemory;
use Repas\Voice\Application\Command\AddSpokenItem\AddSpokenItemResult;
use Repas\Voice\Application\Command\AddSpokenMeal\AddSpokenMealCommand;
use Repas\Voice\Application\Command\AddSpokenMeal\AddSpokenMealHandler;
use Repas\Voice\Domain\Model\DialogueOption;
use Repas\Voice\Domain\Model\DialogueStep;
use Repas\Voice\Domain\Model\SpokenDialogueState;
use Repas\Voice\Domain\Service\SpokenIngredientNamer;

/**
 * Planifier le repas que l'utilisateur vient de dire (ADR 0029 décision 13, VOX-305), avec les deux ports de `Shared` en mémoire : aucune base, aucun
 * noyau. Le choix de la recette et la liste elle-même se prouvent dans `Repas` (`SpokenRecipeResolverFlowTest`, `VoiceMealPlannerFlowTest`) et de
 * bout en bout (`AlexaWebhookApiFlowTest`).
 */
class AddSpokenMealHandlerTest extends TestCase
{
    private SpokenRecipeResolverInMemory $resolver;
    private VoiceMealPlannerInMemory $planner;
    private AddSpokenMealHandler $handler;

    protected function setUp(): void
    {
        $this->resolver = new SpokenRecipeResolverInMemory();
        $this->planner = new VoiceMealPlannerInMemory();
        $this->handler = new AddSpokenMealHandler($this->resolver, $this->planner, new SpokenIngredientNamer());
    }

    private function planned(string $name, int $servings, bool $already = false, bool $listCreated = false, bool $previousListSent = false): PlannedSpokenMeal
    {
        return new PlannedSpokenMeal($name, $servings, $already, $listCreated, $previousListSent);
    }

    public function testARecognizedRecipeIsPlannedForTheAccountServingsAndSaidShortly(): void
    {
        // Given
        $this->resolver->willResolve('hachis parmentier', new ResolvedRecipe('recipe-1', 'Hachis parmentier'));
        $this->planner->willReturn($this->planned('Hachis parmentier', 4));

        // When
        $result = ($this->handler)(new AddSpokenMealCommand('user-1', 'le hachis parmentier', sessionIsOpen: false));

        // Then : cherchée sous son nom sans article, planifiée sans nombre de personnes (celui du compte), la séance se ferme
        $this->assertSame([['user-1', 'hachis parmentier']], $this->resolver->asked);
        $this->assertSame([['user-1', 'recipe-1', null]], $this->planner->planned);
        $this->assertEquals(new AddSpokenItemResult("C'est noté : hachis parmentier, pour 4 personnes.", keepSessionOpen: false, added: true), $result);
        $this->assertSame([], $this->resolver->remembered);
    }

    public function testTheServingsSaidAreForcedAndTheSessionStaysOpenWithoutAnyQuestionWhenItWasOpen(): void
    {
        // Given
        $this->resolver->willResolve('hachis parmentier', new ResolvedRecipe('recipe-1', 'Hachis parmentier'));
        $this->planner->willReturn($this->planned('Hachis parmentier', 6));

        // When
        $result = ($this->handler)(new AddSpokenMealCommand('user-1', 'hachis parmentier', sessionIsOpen: true, servings: 6));

        // Then
        $this->assertSame([['user-1', 'recipe-1', 6]], $this->planner->planned);
        $this->assertEquals(new AddSpokenItemResult("C'est noté : hachis parmentier, pour 6 personnes.", keepSessionOpen: true, added: true), $result);
        $this->assertNull($result->reprompt, 'jamais de relance');
    }

    public function testAListIsStartedAndSaidWhenThereWasNone(): void
    {
        // Given
        $this->resolver->willResolve('gratin', new ResolvedRecipe('recipe-1', 'Gratin'));
        $this->planner->willReturn($this->planned('Gratin', 4, listCreated: true, previousListSent: true));

        // When
        $result = ($this->handler)(new AddSpokenMealCommand('user-1', 'gratin', sessionIsOpen: false));

        // Then
        $this->assertSame("J'ai commencé une nouvelle liste avec gratin, pour 4 personnes. Ta liste précédente est partie au drive.", $result->speech);
    }

    public function testARecipeAlreadyInTheListIsNotPlannedTwiceButTheTotalIsAsked(): void
    {
        // Given : elle y est déjà pour 4
        $this->resolver->willResolve('gratin', new ResolvedRecipe('recipe-1', 'Gratin'));
        $this->planner->willReturn($this->planned('Gratin', 4, already: true));

        // When : même si l'utilisateur avait dit « pour 6 », on ne devine pas
        $result = ($this->handler)(new AddSpokenMealCommand('user-1', 'gratin', sessionIsOpen: false, servings: 6));

        // Then : une question, la séance reste ouverte, l'état porte la recette et son nombre de personnes
        $this->assertSame('Le repas gratin est déjà prévu pour 4 personnes. Tu le veux pour combien, ou on ne change rien ?', $result->speech);
        $this->assertTrue($result->keepSessionOpen);
        $this->assertFalse($result->added);
        $this->assertSame($result->speech, $result->reprompt);
        $this->assertEquals(SpokenDialogueState::askingServings(new DialogueOption('recipe-1', 'Gratin'), 4), $result->nextState);
        $this->assertSame([], $this->planner->changed, 'rien n\'est écrit avant la réponse');
    }

    public function testAnUnknownRecipeProposesTheClosestOnesAndTheProductAndWritesNothing(): void
    {
        // Given
        $this->resolver->willSuggest('gratin', [new RecipeSuggestion('r1', 'gratin dauphinois'), new RecipeSuggestion('r2', 'gratin de pâtes')]);

        // When
        $result = ($this->handler)(new AddSpokenMealCommand('user-1', 'un gratin', sessionIsOpen: false, servings: 6));

        // Then : aucune création de recette, aucune écriture ; l'état garde la phrase, les propositions et le nombre de personnes dit
        $this->assertSame([], $this->planner->planned);
        $this->assertSame('Je ne trouve pas la recette gratin. Tu veux dire gratin dauphinois ou gratin de pâtes ? Ou un produit ?', $result->speech);
        $this->assertTrue($result->keepSessionOpen);
        $this->assertFalse($result->added);
        $this->assertSame('Tu veux dire gratin dauphinois ou gratin de pâtes ? Ou un produit ?', $result->reprompt);
        $this->assertEquals(
            SpokenDialogueState::chooseRecipe('gratin', [new DialogueOption('r1', 'gratin dauphinois'), new DialogueOption('r2', 'gratin de pâtes')], 'gratin', 6),
            $result->nextState,
        );
    }

    public function testAnUnknownRecipeWithNothingCloseAsksWhetherItIsAProduct(): void
    {
        // When
        $result = ($this->handler)(new AddSpokenMealCommand('user-1', 'xyz', sessionIsOpen: true));

        // Then
        $this->assertSame("Je ne trouve pas la recette xyz. C'est un produit ?", $result->speech);
        $this->assertSame(DialogueStep::CHOOSE, $result->nextState?->step);
        $this->assertSame([], $result->nextState->options);
        $this->assertTrue($result->keepSessionOpen, 'une question garde la séance ouverte, même en appel direct');
    }

    public function testOnlyThreeSuggestionsAreKept(): void
    {
        // Given : le port en rend quatre (il ne devrait pas)
        $this->resolver->willSuggest('gratin', array_map(static fn(int $i): RecipeSuggestion => new RecipeSuggestion('r' . $i, 'gratin ' . $i), [1, 2, 3, 4]));

        // When
        $result = ($this->handler)(new AddSpokenMealCommand('user-1', 'gratin', sessionIsOpen: false));

        // Then
        $this->assertCount(3, $result->nextState->options);
    }

    public function testWhenTheProductsWereAlreadyTriedThereIsNoMoreWayOut(): void
    {
        // Given : l'utilisateur a répondu « une recette » à un produit inconnu, et aucune recette ne ressemble
        $direct = ($this->handler)(new AddSpokenMealCommand('user-1', 'xyz', sessionIsOpen: false, otherSideTried: true));
        $open = ($this->handler)(new AddSpokenMealCommand('user-1', 'xyz', sessionIsOpen: true, otherSideTried: true));

        // Then : plus rien à demander, pas d'état, la séance se ferme (ou reste ouverte sans relance si elle l'était)
        $this->assertEquals(new AddSpokenItemResult("Je ne trouve rien qui s'appelle xyz.", keepSessionOpen: false, added: false), $direct);
        $this->assertEquals(new AddSpokenItemResult("Je ne trouve rien qui s'appelle xyz.", keepSessionOpen: true, added: false), $open);
    }

    public function testSuggestionsAfterAProductSwitchDoNotOfferTheProductAgain(): void
    {
        // Given
        $this->resolver->willSuggest('gratin', [new RecipeSuggestion('r1', 'gratin dauphinois')]);

        // When
        $result = ($this->handler)(new AddSpokenMealCommand('user-1', 'gratin', sessionIsOpen: false, otherSideTried: true));

        // Then
        $this->assertSame('Je ne trouve pas la recette gratin. Tu veux dire gratin dauphinois ?', $result->speech);
        $this->assertTrue($result->nextState?->otherSideTried);
    }

    public function testCorrectingTheProposalsRemembersTheOriginalPhraseForTheRecipeThatWasPlanned(): void
    {
        // Given
        $this->resolver->willResolve('hachis parmentier', new ResolvedRecipe('recipe-1', 'Hachis parmentier'));
        $this->planner->willReturn($this->planned('Hachis parmentier', 4));

        // When : « hachis » -> propositions -> « le hachis parmentier »
        $result = ($this->handler)(new AddSpokenMealCommand('user-1', 'hachis parmentier', sessionIsOpen: false, rememberAs: 'hachis'));

        // Then : planifiée PUIS retenue, et Alexa promet de s'en souvenir
        $this->assertSame([['user-1', 'hachis', 'recipe-1']], $this->resolver->remembered);
        $this->assertSame("C'est noté : hachis parmentier, pour 4 personnes. Je m'en souviendrai.", $result->speech);
    }

    public function testAnExplicitlyChosenRecipeIsPlannedAndItsPhraseRemembered(): void
    {
        // Given
        $this->planner->willReturn($this->planned('Gratin dauphinois', 4, listCreated: true));

        // When : une proposition choisie (le slug de l'option n'est jamais cru : le port vérifie)
        $result = $this->handler->plan('user-1', new DialogueOption('r1', 'gratin dauphinois'), 5, 'gratin', keepSessionOpen: false);

        // Then
        $this->assertSame([['user-1', 'r1', 5]], $this->planner->planned);
        $this->assertSame([['user-1', 'gratin', 'r1']], $this->resolver->remembered);
        $this->assertSame("J'ai commencé une nouvelle liste avec gratin dauphinois, pour 4 personnes. Je m'en souviendrai.", $result->speech);
    }

    public function testAChosenRecipeThatIsAlreadyInTheListStillRemembersThePhraseAndAsksTheTotal(): void
    {
        // Given
        $this->planner->willReturn($this->planned('Gratin dauphinois', 4, already: true));

        // When
        $result = $this->handler->plan('user-1', new DialogueOption('r1', 'gratin dauphinois'), null, 'gratin', keepSessionOpen: false);

        // Then : il a choisi cette recette pour cette phrase, qu'elle soit déjà planifiée ou non
        $this->assertSame([['user-1', 'gratin', 'r1']], $this->resolver->remembered);
        $this->assertSame(DialogueStep::SERVINGS, $result->nextState?->step);
    }

    public function testAForgedRecipeOfAnotherUserWritesNothingAndFailsTheWholeCommand(): void
    {
        // Given : le port refuse la recette d'un autre utilisateur
        $this->planner->willThrow(RecipeException::notFound('recipe-of-someone-else'));

        // Then : l'exception n'est pas attrapée (le bus transactionnel annule tout), rien n'est retenu
        $this->expectExceptionObject(RecipeException::notFound('recipe-of-someone-else'));
        try {
            $this->handler->plan('user-1', new DialogueOption('recipe-of-someone-else', 'x'), null, 'gratin', keepSessionOpen: false);
        } finally {
            $this->assertSame([], $this->resolver->remembered);
        }
    }

    public function testAPhraseWithoutAnyNameIsAskedAgainWithAnExample(): void
    {
        // When : « 12 » n'est pas un nom (aucune lettre)
        $result = ($this->handler)(new AddSpokenMealCommand('user-1', '12', sessionIsOpen: false));

        // Then
        $this->assertSame("Je n'ai pas compris. Dis par exemple : planifie le repas hachis parmentier.", $result->speech);
        $this->assertSame([], $this->resolver->asked);
        $this->assertNull($result->nextState);
    }

    public function testTheNewTotalReplacesTheOldOneAndTheSameNumberWritesNothing(): void
    {
        // Given
        $recipe = new DialogueOption('recipe-1', 'Gratin');
        $this->planner->willChange(ChangedMealServings::changed('Gratin', 6));

        // When
        $changed = $this->handler->changeServings('user-1', $recipe, 4, 6);
        $same = $this->handler->changeServings('user-1', $recipe, 4, 4);

        // Then
        $this->assertSame([['user-1', 'recipe-1', 6]], $this->planner->changed, 'une seule écriture : le même nombre ne change rien');
        $this->assertEquals(new AddSpokenItemResult("C'est changé : gratin, pour 6 personnes.", keepSessionOpen: false, added: true), $changed);
        $this->assertEquals(new AddSpokenItemResult("D'accord, je ne change rien.", keepSessionOpen: false, added: false), $same);
    }

    public function testARecipeThatLeftTheListSinceTheQuestionIsSaidSo(): void
    {
        // Given
        $this->planner->willChange(ChangedMealServings::notPlanned('Gratin'));

        // When
        $result = $this->handler->changeServings('user-1', new DialogueOption('recipe-1', 'Gratin'), 4, 6);

        // Then
        $this->assertEquals(new AddSpokenItemResult("Ce repas n'est plus dans ta liste.", keepSessionOpen: false, added: false), $result);
    }

    public function testTheOriginalPhraseOfAnUnknownRecipeIsKeptInTheStateForTheProductSearch(): void
    {
        // When : « ajoute le repas pour chien », dit pour un produit
        $result = ($this->handler)(new AddSpokenMealCommand('user-1', 'pour chien', sessionIsOpen: false, originalPhrase: 'le repas pour chien'));

        // Then : la recette cherchée est « pour chien », l'état garde la phrase entière
        $this->assertSame("Je ne trouve pas la recette pour chien. C'est un produit ?", $result->speech);
        $this->assertSame('pour chien', $result->nextState?->phrase);
        $this->assertSame('le repas pour chien', $result->nextState->originalPhrase);
    }
}
