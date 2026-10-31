<?php

namespace Repas\Tests\Voice\Acl;

use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\ChangeSpokenMealServings\ChangeSpokenMealServingsCommand;
use Repas\Repas\Application\Command\PlanSpokenMeal\PlanSpokenMealCommand;
use Repas\Repas\Application\Command\RemoveSpokenMeal\RemoveSpokenMealCommand;
use Repas\Repas\Domain\Exception\MealPlanningException;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Model\MealPlanResult;
use Repas\Repas\Domain\Model\MealRemovalResult;
use Repas\Repas\Domain\Model\MealServingsResult;
use Repas\Tests\Helper\RepasForVoice;
use Repas\Voice\Domain\Exception\SpokenMealFailure;
use Repas\Voice\Domain\Model\ChangedMealServings;
use Repas\Voice\Domain\Model\PlannedSpokenMeal;
use Repas\Voice\Domain\Model\RemovedSpokenMeal;
use Repas\Voice\Domain\Model\ResolvedRecipe;
use Repas\Voice\Domain\Model\RecipeSuggestion;
use Repas\Voice\Domain\Model\SpokenRecipeResolution;

/**
 * La transposition des couches anticorruption `RecipeResolutionCommandAdapter` et `MealPlanCommandAdapter` (ADR 0035) : ce que `Repas` rend (recette
 * classée, propositions, repas planifié, changé, retiré) et ses échecs connus deviennent les types de `Voice`. Le classement lui-même est prouvé dans
 * `Repas`, les dialogues par les tests des handlers.
 */
class RepasMealAdaptersTest extends TestCase
{
    private RepasForVoice $repas;

    protected function setUp(): void
    {
        $this->repas = new RepasForVoice();
    }

    public function testARecipeOfTheUserIsFoundAndSuggestionsAreMappedOneToOne(): void
    {
        // Given
        $this->repas->ownsRecipe('r1', 'Gratin dauphinois')->ownsRecipe('r2', 'Gratin de pâtes');
        $port = $this->repas->recipeResolutionPort();

        // When
        $found = $port->resolveOrSuggest('user-1', 'gratin dauphinois');
        $proposed = $port->resolveOrSuggest('user-1', 'gratin');

        // Then
        $this->assertEquals(SpokenRecipeResolution::found(new ResolvedRecipe('r1', 'Gratin dauphinois')), $found);
        $this->assertNull($proposed->recipe);
        $this->assertEqualsCanonicalizing([new RecipeSuggestion('r1', 'Gratin dauphinois'), new RecipeSuggestion('r2', 'Gratin de pâtes')], $proposed->suggestions);
        $this->assertTrue($proposed->hasRecipes);
    }

    public function testAUserWithoutAnyRecipeIsToldSo(): void
    {
        // When
        $resolution = $this->repas->recipeResolutionPort()->resolveOrSuggest('user-1', 'gratin');

        // Then
        $this->assertEquals(SpokenRecipeResolution::unknown(hasRecipes: false), $resolution);
    }

    public function testRememberingAPhraseForARecipeSendsTheCommandAndANotFoundErrorIsNotHidden(): void
    {
        // Given
        $port = $this->repas->recipeResolutionPort();

        // When
        $port->remember('user-1', 'le plat du dimanche', 'r1');

        // Then
        $this->assertSame([['user-1', 'le plat du dimanche', 'r1']], $this->repas->rememberedRecipes());

        // Given : la recette est à un autre
        $this->repas->willFailToRememberRecipe(RecipeException::notFound('r9'));
        $this->expectExceptionObject(RecipeException::notFound('r9'));

        // When
        $port->remember('user-1', 'gratin', 'r9');
    }

    public function testThePlannedMealTheChangeAndTheRemovalAreMappedToTheTypesOfVoice(): void
    {
        // Given
        $this->repas->bus->willAnswer(PlanSpokenMealCommand::class, static fn(object $command): MealPlanResult => new MealPlanResult('Gratin', 6, alreadyPlanned: true, listCreated: false, previousListSent: true));
        $this->repas->bus->willAnswer(ChangeSpokenMealServingsCommand::class, static fn(object $command): MealServingsResult => MealServingsResult::notPlanned('Gratin'));
        $this->repas->bus->willAnswer(RemoveSpokenMealCommand::class, static fn(object $command): MealRemovalResult => $command->servings === null ? MealRemovalResult::removed('Gratin') : MealRemovalResult::lowered('Gratin', 2));
        $port = $this->repas->mealPlanPort();

        // When / Then
        $this->assertEquals(new PlannedSpokenMeal('Gratin', 6, alreadyPlanned: true, listCreated: false, previousListSent: true), $port->plan('user-1', 'r1', 6));
        $this->assertEquals(ChangedMealServings::notPlanned('Gratin'), $port->changeServings('user-1', 'r1', 4));
        $this->assertEquals(RemovedSpokenMeal::removed('Gratin'), $port->removeMeal('user-1', 'r1'));
        $this->assertEquals(RemovedSpokenMeal::lowered('Gratin', 2), $port->removeMeal('user-1', 'r1', 2));

        // Then : une planification demandée sans nombre laisse le choix au compte
        $this->repas->bus->willAnswer(PlanSpokenMealCommand::class, static fn(object $command): MealPlanResult => new MealPlanResult('Gratin', 4, false, true, false));
        $this->assertEquals(new PlannedSpokenMeal('Gratin', 4, alreadyPlanned: false, listCreated: true, previousListSent: false), $port->plan('user-1', 'r1'));
        $this->assertSame([['user-1', 'r1', 6], ['user-1', 'r1', null]], $this->repas->mealsPlanned());
    }

    public function testTheListsOfATypeAndASentListInTheGroupAreMappedToTheTypesOfVoice(): void
    {
        // Given : le repas est allé dans Boucherie et Marché (sans la liste principale), puis une liste du groupe est partie au drive
        $this->repas->bus->willAnswer(PlanSpokenMealCommand::class, static fn(object $command): MealPlanResult => new MealPlanResult('Gratin', 4, false, false, false, ['Boucherie', 'Marché'], false));
        $this->repas->bus->willAnswer(ChangeSpokenMealServingsCommand::class, static fn(object $command): MealServingsResult => MealServingsResult::listSent('Gratin'));
        $this->repas->bus->willAnswer(RemoveSpokenMealCommand::class, static fn(object $command): MealRemovalResult => MealRemovalResult::listSent('Gratin'));
        $port = $this->repas->mealPlanPort();

        // When / Then
        $this->assertEquals(new PlannedSpokenMeal('Gratin', 4, false, false, false, ['Boucherie', 'Marché'], false), $port->plan('user-1', 'r1'));
        $this->assertEquals(ChangedMealServings::listSent('Gratin'), $port->changeServings('user-1', 'r1', 8));
        $this->assertEquals(RemovedSpokenMeal::listSent('Gratin'), $port->removeMeal('user-1', 'r1'));
    }

    public function testAMissingConversionOfRepasBecomesTheFailureOfVoiceWithTheIngredientNamed(): void
    {
        // Given
        $this->repas->willFailToWriteMeals(MealPlanningException::missingConversion('Blanquette', 'Farine'));
        $port = $this->repas->mealPlanPort();

        foreach ([
            static fn() => $port->plan('user-1', 'r1'),
            static fn() => $port->changeServings('user-1', 'r1', 4),
            static fn() => $port->removeMeal('user-1', 'r1', 2),
        ] as $call) {
            // When
            try {
                $call();
                $this->fail('Un échec connu doit remonter.');
            } catch (SpokenMealFailure $failure) {
                // Then
                $this->assertSame([SpokenMealFailure::MISSING_CONVERSION, 'Blanquette', 'Farine'], [$failure->reason, $failure->recipeName, $failure->ingredientName]);
                $this->assertInstanceOf(MealPlanningException::class, $failure->getPrevious());
            }
        }
    }

    public function testARecipeThatIsGoneBecomesTheFailureOfVoiceAndAnyOtherErrorIsNotHidden(): void
    {
        // Given
        $this->repas->willFailToWriteMeals(MealPlanningException::recipeGone());
        $port = $this->repas->mealPlanPort();

        // When / Then
        try {
            $port->plan('user-1', 'r1');
            $this->fail('Un échec connu doit remonter.');
        } catch (SpokenMealFailure $failure) {
            $this->assertSame([SpokenMealFailure::RECIPE_GONE, null], [$failure->reason, $failure->recipeName]);
        }

        // Given : une autre erreur de domaine
        $this->repas->willFailToWriteMeals(RecipeException::notFound('r9'));
        $this->expectExceptionObject(RecipeException::notFound('r9'));

        // When
        $port->plan('user-1', 'r9');
    }
}
