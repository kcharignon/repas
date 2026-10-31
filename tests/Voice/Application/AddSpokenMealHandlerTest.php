<?php

namespace Repas\Tests\Voice\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Tests\Helper\RepasForVoice;
use Repas\Voice\Application\Command\AddSpokenItem\AddSpokenItemResult;
use Repas\Voice\Application\Command\AddSpokenMeal\AddSpokenMealCommand;
use Repas\Voice\Application\Command\AddSpokenMeal\AddSpokenMealHandler;
use Repas\Voice\Domain\Model\DialogueOption;
use Repas\Voice\Domain\Model\ChangedMealServings;
use Repas\Voice\Domain\Model\DialogueStep;
use Repas\Voice\Domain\Model\PlannedSpokenMeal;
use Repas\Voice\Domain\Model\SpokenDialogueState;
use Repas\Voice\Domain\Service\SpokenIngredientNamer;

/**
 * Planifier le repas que l'utilisateur vient de dire (ADR 0029 décision 13, VOX-305) par la vraie chaîne (adapters de `Voice`, Open Host Services et
 * classeur de `Repas`, `RepasForVoice`) sur les doubles en mémoire : aucune base, aucun noyau. Le choix de la recette et la liste elle-même se prouvent
 * dans `Repas` (`RecipePhraseCommandOHSFlowTest`, `MealPlanCommandOHSFlowTest`) et de bout en bout (`AlexaWebhookApiFlowTest`).
 */
class AddSpokenMealHandlerTest extends TestCase
{
    private RepasForVoice $repas;
    private AddSpokenMealHandler $handler;

    protected function setUp(): void
    {
        $this->repas = new RepasForVoice();
        $this->handler = new AddSpokenMealHandler($this->repas->recipeResolutionPort(), $this->repas->mealPlanPort(), new SpokenIngredientNamer());
    }

    private function planned(string $name, int $servings, bool $already = false, bool $listCreated = false, bool $previousListSent = false): PlannedSpokenMeal
    {
        return new PlannedSpokenMeal($name, $servings, $already, $listCreated, $previousListSent);
    }

    public function testARecognizedRecipeIsPlannedForTheAccountServingsAndSaidShortly(): void
    {
        // Given
        $this->repas->ownsRecipe('recipe-1', 'Hachis parmentier');
        $this->repas->willPlanMeal($this->planned('Hachis parmentier', 4));

        // When
        $result = ($this->handler)(new AddSpokenMealCommand('user-1', 'le hachis parmentier', sessionIsOpen: false));

        // Then : cherchée sous son nom sans article, planifiée sans nombre de personnes (celui du compte), la séance se ferme
        $this->assertSame([['user-1', 'hachis parmentier']], $this->repas->askedRecipes);
        $this->assertSame([['user-1', 'recipe-1', null]], $this->repas->mealsPlanned());
        $this->assertEquals(new AddSpokenItemResult("C'est noté : hachis parmentier, pour 4 personnes.", keepSessionOpen: false, added: true), $result);
        $this->assertSame([], $this->repas->rememberedRecipes());
    }

    public function testTheServingsSaidAreForcedAndTheSessionStaysOpenWithoutAnyQuestionWhenItWasOpen(): void
    {
        // Given
        $this->repas->ownsRecipe('recipe-1', 'Hachis parmentier');
        $this->repas->willPlanMeal($this->planned('Hachis parmentier', 6));

        // When
        $result = ($this->handler)(new AddSpokenMealCommand('user-1', 'hachis parmentier', sessionIsOpen: true, servings: 6));

        // Then
        $this->assertSame([['user-1', 'recipe-1', 6]], $this->repas->mealsPlanned());
        $this->assertEquals(new AddSpokenItemResult("C'est noté : hachis parmentier, pour 6 personnes.", keepSessionOpen: true, added: true), $result);
        $this->assertNull($result->reprompt, 'jamais de relance');
    }

    public function testAListIsStartedAndSaidWhenThereWasNone(): void
    {
        // Given
        $this->repas->ownsRecipe('recipe-1', 'Gratin');
        $this->repas->willPlanMeal($this->planned('Gratin', 4, listCreated: true, previousListSent: true));

        // When
        $result = ($this->handler)(new AddSpokenMealCommand('user-1', 'gratin', sessionIsOpen: false));

        // Then
        $this->assertSame("J'ai commencé une nouvelle liste avec gratin, pour 4 personnes. Ta liste précédente est partie au drive.", $result->speech);
    }

    public function testARecipeAlreadyInTheListIsNotPlannedTwiceButTheTotalIsAsked(): void
    {
        // Given : elle y est déjà pour 4
        $this->repas->ownsRecipe('recipe-1', 'Gratin');
        $this->repas->willPlanMeal($this->planned('Gratin', 4, already: true));

        // When : même si l'utilisateur avait dit « pour 6 », on ne devine pas
        $result = ($this->handler)(new AddSpokenMealCommand('user-1', 'gratin', sessionIsOpen: false, servings: 6));

        // Then : une question, la séance reste ouverte, l'état porte la recette et son nombre de personnes
        $this->assertSame('Le repas gratin est déjà prévu pour 4 personnes. Tu le veux pour combien, ou on ne change rien ?', $result->speech);
        $this->assertTrue($result->keepSessionOpen);
        $this->assertFalse($result->added);
        $this->assertSame($result->speech, $result->reprompt);
        $this->assertEquals(SpokenDialogueState::askingServings(new DialogueOption('recipe-1', 'Gratin'), 4), $result->nextState);
        $this->assertSame([], $this->repas->mealsChanged(), 'rien n\'est écrit avant la réponse');
    }

    public function testAnUnknownRecipeProposesTheClosestOnesAndTheProductAndWritesNothing(): void
    {
        // Given
        $this->repas->ownsRecipe('r1', 'gratin dauphinois')->ownsRecipe('r2', 'gratin de pâtes');

        // When
        $result = ($this->handler)(new AddSpokenMealCommand('user-1', 'un gratin', sessionIsOpen: false, servings: 6));

        // Then : aucune création de recette, aucune écriture ; l'état garde la phrase, les propositions et le nombre de personnes dit
        $this->assertSame([], $this->repas->mealsPlanned());
        $this->assertSame('Je ne trouve pas la recette gratin. Tu veux dire gratin de pâtes ou gratin dauphinois ? Ou un produit ?', $result->speech);
        $this->assertTrue($result->keepSessionOpen);
        $this->assertFalse($result->added);
        $this->assertSame('Tu veux dire gratin de pâtes ou gratin dauphinois ? Ou un produit ?', $result->reprompt);
        $this->assertEquals(
            SpokenDialogueState::chooseRecipe('gratin', [new DialogueOption('r2', 'gratin de pâtes'), new DialogueOption('r1', 'gratin dauphinois')], 'gratin', 6),
            $result->nextState,
        );
    }

    public function testAnUnknownRecipeWithNothingCloseStillProposesTheClosestOnesAndAsksWhetherItIsAProduct(): void
    {
        // Given : aucune recette ne ressemble à « xyz », mais mieux vaut une proposition lointaine qu'un silence
        $this->repas->ownsRecipe('r1', 'Gratin');

        // When
        $result = ($this->handler)(new AddSpokenMealCommand('user-1', 'xyz', sessionIsOpen: true));

        // Then
        $this->assertSame('Je ne trouve pas la recette xyz. Tu veux dire gratin ? Ou un produit ?', $result->speech);
        $this->assertSame(DialogueStep::CHOOSE, $result->nextState?->step);
        $this->assertEquals([new DialogueOption('r1', 'Gratin')], $result->nextState->options);
        $this->assertTrue($result->keepSessionOpen, 'une question garde la séance ouverte, même en appel direct');
    }

    public function testAUserWithoutAnyRecipeIsToldSoAndAskedWhetherItIsAProduct(): void
    {
        // Given
        // L'utilisateur n'a aucune recette (par défaut)

        // When
        $result = ($this->handler)(new AddSpokenMealCommand('user-1', 'blanquette de veaux', sessionIsOpen: false));

        // Then : « aucune recette » plutôt qu'un « je ne trouve pas » qui n'aide pas ; la question garde la séance ouverte
        $this->assertSame("Tu n'as encore aucune recette dans Repas. C'est un produit ?", $result->speech);
        $this->assertSame(DialogueStep::CHOOSE, $result->nextState?->step);
        $this->assertTrue($result->keepSessionOpen);
        $this->assertSame([], $this->repas->mealsPlanned());
    }

    public function testAUserWithoutAnyRecipeWhoseProductWasAlreadyTriedHearsThatNothingWasFound(): void
    {
        // Given : l'utilisateur a répondu « une recette » après un produit inconnu
        // L'utilisateur n'a aucune recette (par défaut)

        // When
        $result = ($this->handler)(new AddSpokenMealCommand('user-1', 'xyz', sessionIsOpen: true, otherSideTried: true));

        // Then : plus d'issue
        $this->assertSame('Je ne trouve rien qui s\'appelle xyz.', $result->speech);
        $this->assertNull($result->nextState);
    }

    public function testOnlyThreeSuggestionsAreKept(): void
    {
        // Given : quatre recettes se ressemblent
        foreach ([1, 2, 3, 4] as $i) {
            $this->repas->ownsRecipe('r' . $i, 'gratin ' . $i);
        }

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
        $this->repas->ownsRecipe('r1', 'Tarte aux pommes');

        // When
        $result = ($this->handler)(new AddSpokenMealCommand('user-1', 'gratin', sessionIsOpen: false, otherSideTried: true));

        // Then
        $this->assertSame('Je ne trouve pas la recette gratin. Tu veux dire tarte aux pommes ?', $result->speech);
        $this->assertTrue($result->nextState?->otherSideTried);
    }

    public function testCorrectingTheProposalsRemembersTheOriginalPhraseForTheRecipeThatWasPlanned(): void
    {
        // Given
        $this->repas->ownsRecipe('recipe-1', 'Hachis parmentier');
        $this->repas->willPlanMeal($this->planned('Hachis parmentier', 4));

        // When : « hachis » -> propositions -> « le hachis parmentier »
        $result = ($this->handler)(new AddSpokenMealCommand('user-1', 'hachis parmentier', sessionIsOpen: false, rememberAs: 'hachis'));

        // Then : planifiée PUIS retenue, et Alexa promet de s'en souvenir
        $this->assertSame([['user-1', 'hachis', 'recipe-1']], $this->repas->rememberedRecipes());
        $this->assertSame("C'est noté : hachis parmentier, pour 4 personnes. Je m'en souviendrai.", $result->speech);
    }

    public function testAnExplicitlyChosenRecipeIsPlannedAndItsPhraseRemembered(): void
    {
        // Given
        $this->repas->willPlanMeal($this->planned('Gratin dauphinois', 4, listCreated: true));

        // When : une proposition choisie (le slug de l'option n'est jamais cru : le port vérifie)
        $result = $this->handler->plan('user-1', new DialogueOption('r1', 'gratin dauphinois'), 5, 'gratin', keepSessionOpen: false);

        // Then
        $this->assertSame([['user-1', 'r1', 5]], $this->repas->mealsPlanned());
        $this->assertSame([['user-1', 'gratin', 'r1']], $this->repas->rememberedRecipes());
        $this->assertSame("J'ai commencé une nouvelle liste avec gratin dauphinois, pour 4 personnes. Je m'en souviendrai.", $result->speech);
    }

    public function testAChosenRecipeThatIsAlreadyInTheListStillRemembersThePhraseAndAsksTheTotal(): void
    {
        // Given
        $this->repas->willPlanMeal($this->planned('Gratin dauphinois', 4, already: true));

        // When
        $result = $this->handler->plan('user-1', new DialogueOption('r1', 'gratin dauphinois'), null, 'gratin', keepSessionOpen: false);

        // Then : il a choisi cette recette pour cette phrase, qu'elle soit déjà planifiée ou non
        $this->assertSame([['user-1', 'gratin', 'r1']], $this->repas->rememberedRecipes());
        $this->assertSame(DialogueStep::SERVINGS, $result->nextState?->step);
    }

    public function testAForgedRecipeOfAnotherUserWritesNothingAndFailsTheWholeCommand(): void
    {
        // Given : le port refuse la recette d'un autre utilisateur
        $this->repas->willFailToWriteMeals(RecipeException::notFound('recipe-of-someone-else'));

        // Then : l'exception n'est pas attrapée (le bus transactionnel annule tout), rien n'est retenu
        $this->expectExceptionObject(RecipeException::notFound('recipe-of-someone-else'));
        try {
            $this->handler->plan('user-1', new DialogueOption('recipe-of-someone-else', 'x'), null, 'gratin', keepSessionOpen: false);
        } finally {
            $this->assertSame([], $this->repas->rememberedRecipes());
        }
    }

    public function testAPhraseWithoutAnyNameIsAskedAgainWithAnExample(): void
    {
        // When : « 12 » n'est pas un nom (aucune lettre)
        $result = ($this->handler)(new AddSpokenMealCommand('user-1', '12', sessionIsOpen: false));

        // Then
        $this->assertSame("Je n'ai pas compris. Dis par exemple : planifie le repas hachis parmentier.", $result->speech);
        $this->assertSame([], $this->repas->askedRecipes);
        $this->assertNull($result->nextState);
    }

    public function testTheNewTotalReplacesTheOldOneAndTheSameNumberWritesNothing(): void
    {
        // Given
        $recipe = new DialogueOption('recipe-1', 'Gratin');
        $this->repas->willChangeMealServings(ChangedMealServings::changed('Gratin', 6));

        // When
        $changed = $this->handler->changeServings('user-1', $recipe, 4, 6);
        $same = $this->handler->changeServings('user-1', $recipe, 4, 4);

        // Then
        $this->assertSame([['user-1', 'recipe-1', 6]], $this->repas->mealsChanged(), 'une seule écriture : le même nombre ne change rien');
        $this->assertEquals(new AddSpokenItemResult("C'est changé : gratin, pour 6 personnes.", keepSessionOpen: false, added: true), $changed);
        $this->assertEquals(new AddSpokenItemResult("D'accord, je ne change rien.", keepSessionOpen: false, added: false), $same);
    }

    public function testARecipeThatLeftTheListSinceTheQuestionIsSaidSo(): void
    {
        // Given
        $this->repas->willChangeMealServings(ChangedMealServings::notPlanned('Gratin'));

        // When
        $result = $this->handler->changeServings('user-1', new DialogueOption('recipe-1', 'Gratin'), 4, 6);

        // Then
        $this->assertEquals(new AddSpokenItemResult("Ce repas n'est plus dans ta liste.", keepSessionOpen: false, added: false), $result);
    }

    public function testTheOriginalPhraseOfAnUnknownRecipeIsKeptInTheStateForTheProductSearch(): void
    {
        // When : « ajoute le repas pour chien », dit pour un produit (l'utilisateur n'a aucune recette)
        $result = ($this->handler)(new AddSpokenMealCommand('user-1', 'pour chien', sessionIsOpen: false, originalPhrase: 'le repas pour chien'));

        // Then : la recette cherchée est « pour chien », l'état garde la phrase entière
        $this->assertSame("Tu n'as encore aucune recette dans Repas. C'est un produit ?", $result->speech);
        $this->assertSame('pour chien', $result->nextState?->phrase);
        $this->assertSame('le repas pour chien', $result->nextState->originalPhrase);
    }
}
