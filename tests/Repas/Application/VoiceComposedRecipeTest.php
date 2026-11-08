<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\ChangeSpokenMealServings\ChangeSpokenMealServingsCommand;
use Repas\Repas\Application\Command\ChangeSpokenMealServings\ChangeSpokenMealServingsHandler;
use Repas\Repas\Application\Command\PlanSpokenMeal\PlanSpokenMealCommand;
use Repas\Repas\Application\Command\PlanSpokenMeal\PlanSpokenMealHandler;
use Repas\Repas\Application\Command\RemoveMealFromPlan\removeMealFromPlanHandler;
use Repas\Repas\Application\Command\RemoveSpokenMeal\RemoveSpokenMealCommand;
use Repas\Repas\Application\Command\RemoveSpokenMeal\RemoveSpokenMealHandler;
use Repas\Repas\Application\Command\UpdateServingMeal\UpdateServingMealHandler;
use Repas\Repas\Application\Port\DefaultServingCommandPort;
use Repas\Repas\Application\Service\SpokenListName;
use Repas\Repas\Domain\Exception\MealPlanningException;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\ComposedScenario;
use Repas\Tests\Helper\FlatRows;
use Repas\Tests\Helper\InMemoryRepository\ConversionInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UnitInMemoryRepository;
use Repas\Tests\Helper\PartLines;

/**
 * La commande vocale d'un repas dont la recette en utilise une autre (ADR 0040 décisions 16 et 17, COMP-004), avec les vrais handlers sur des doubles
 * en mémoire : toutes les recettes de l'utilisateur se planifient à la voix, la pâte seule comme la tarte ; retirer par la voix une recette ABSORBÉE
 * par son parent n'est pas possible (seuls les repas se retirent) ; une conversion manquante dans la pâte nomme la pâte.
 */
class VoiceComposedRecipeTest extends TestCase
{
    public function testTheDoughAloneIsPlannedByVoiceLikeAnyRecipe(): void
    {
        // Given
        $s = new ComposedScenario();
        $s->list();

        // When : « planifie la pâte sablée pour six personnes »
        $result = ($this->plan($s))(new PlanSpokenMealCommand($s->owner->getId(), $s->dough->getId(), 'new-list', 6));

        // Then : un repas ordinaire, ses seules lignes
        $this->assertSame(['Pâte sablée', 6, false], [$result->recipeName, $result->servings, $result->alreadyPlanned]);
        $list = $s->reload();
        $this->assertSame(['beurre', 'farine'], array_keys($s->rows($list)));
        $this->assertNotNull($s->meal($list, $s->dough));
    }

    public function testThePieIsPlannedByVoiceWithTheLinesOfItsDough(): void
    {
        // Given
        $s = new ComposedScenario();
        $s->list();

        // When : « planifie la tarte au citron »
        $result = ($this->plan($s))(new PlanSpokenMealCommand($s->owner->getId(), $s->pie->getId(), 'new-list'));

        // Then : le sucre et les œufs de la tarte, la farine et le beurre de la pâte, au nombre de personnes du compte
        $this->assertSame(['Tarte au citron', 4], [$result->recipeName, $result->servings]);
        $rows = $s->rows($s->reload());
        $this->assertSame(['beurre', 'farine', 'oeuf', 'sucre'], array_keys($rows));
        $this->assertEqualsWithDelta(0.25, $rows['farine'], 1e-9);
    }

    public function testAnAlreadyPlannedPieIsNotPlannedTwiceButTheDoughAloneStillCanBe(): void
    {
        // Given : la tarte est déjà dans la liste
        $s = new ComposedScenario();
        $s->list();
        ($this->plan($s))(new PlanSpokenMealCommand($s->owner->getId(), $s->pie->getId(), 'new-list'));

        // When
        $again = ($this->plan($s))(new PlanSpokenMealCommand($s->owner->getId(), $s->pie->getId(), 'new-list'));
        $dough = ($this->plan($s))(new PlanSpokenMealCommand($s->owner->getId(), $s->dough->getId(), 'new-list', 6));

        // Then : la tarte n'est pas remise, la pâte seule est permise (les quantités s'additionnent)
        $this->assertTrue($again->alreadyPlanned);
        $this->assertFalse($dough->alreadyPlanned);
        $this->assertEqualsWithDelta(0.5, $s->rows($s->reload())['farine'], 1e-9);
    }

    public function testADoughAbsorbedByItsParentCannotBeRemovedByVoice(): void
    {
        // Given : seule la tarte est planifiée, la pâte y est absorbée
        $s = new ComposedScenario();
        $s->list();
        ($s->planned())($s->plan($s->pie));
        $before = $s->rows($s->reload());

        // When : « enlève le repas pâte sablée »
        $result = ($this->removeMeal($s))(new RemoveSpokenMealCommand($s->owner->getId(), $s->dough->getId()));

        // Then : « pas dans ta liste », rien n'est écrit
        $this->assertFalse($result->found);
        $this->assertSame($before, $s->rows($s->reload()));
        $this->assertNotNull($s->meal($s->reload(), $s->pie));
    }

    public function testThePieIsRemovedByVoiceWithTheLinesOfItsDoughAndLoweredByTwoPeople(): void
    {
        // Given
        $s = new ComposedScenario();
        $s->list();
        ($s->planned())($s->plan($s->pie));

        // When : « enlève deux personnes du repas tarte au citron »
        $lowered = ($this->removeMeal($s))(new RemoveSpokenMealCommand($s->owner->getId(), $s->pie->getId(), 2));

        // Then : 4 -> 2 personnes, toutes les lignes sont réduites de moitié, celles de la pâte comprises
        $this->assertSame([false, 2], [$lowered->removedAll, $lowered->servings]);
        $rows = $s->rows($s->reload());
        $this->assertEqualsWithDelta(0.125, $rows['farine'], 1e-9);
        $this->assertEqualsWithDelta(0.05, $rows['sucre'], 1e-9);

        // When : « enlève le repas tarte au citron »
        $removed = ($this->removeMeal($s))(new RemoveSpokenMealCommand($s->owner->getId(), $s->pie->getId()));

        // Then : tout part
        $this->assertTrue($removed->removedAll);
        $this->assertSame([], $s->rows($s->reload()));
    }

    public function testTheServingsOfThePieChangeByVoiceForBothLevels(): void
    {
        // Given
        $s = new ComposedScenario();
        $s->list();
        ($s->planned())($s->plan($s->pie));

        // When : « six » en réponse à « Tarte au citron est déjà prévue pour 4 personnes »
        $result = (new ChangeSpokenMealServingsHandler($s->lists, $s->recipes, $s->serving(), $s->typed->group))(new ChangeSpokenMealServingsCommand($s->owner->getId(), $s->pie->getId(), 6));

        // Then
        $this->assertSame(6, $result->servings);
        $rows = $s->rows($s->reload());
        $this->assertEqualsWithDelta(0.375, $rows['farine'], 1e-9);
        $this->assertEqualsWithDelta(3.0, $rows['oeuf'], 1e-9);
    }

    public function testAMissingConversionInTheDoughIsNamedAndNothingIsWritten(): void
    {
        // Given : la plaquette de beurre de la PÂTE ne se convertit pas
        $s = new ComposedScenario(butterConversion: false);
        $s->list();

        // When
        try {
            ($this->plan($s))(new PlanSpokenMealCommand($s->owner->getId(), $s->pie->getId(), 'new-list'));
            $this->fail('Une conversion manquante refuse la planification.');
        } catch (MealPlanningException $failure) {
            // Then : la recette dite, l'ingrédient en cause ET la recette utilisée d'où il vient
            $this->assertSame([MealPlanningException::MISSING_CONVERSION, 'Tarte au citron', 'Beurre', 'Pâte sablée'], [$failure->reason, $failure->recipeName, $failure->ingredientName, $failure->componentName]);
        }
        $this->assertSame([], $s->rows($s->reload()));

        // When : la pâte planifiée seule échoue aussi, mais c'est elle qu'on a dite, sa propre ligne : aucune recette utilisée
        try {
            ($this->plan($s))(new PlanSpokenMealCommand($s->owner->getId(), $s->dough->getId(), 'new-list', 6));
            $this->fail('Une conversion manquante refuse la planification.');
        } catch (MealPlanningException $failure) {
            $this->assertSame(['Pâte sablée', 'Beurre', null], [$failure->recipeName, $failure->ingredientName, $failure->componentName]);
        }
        $this->assertSame([], $s->rows($s->reload()));
    }

    public function testAConversionThatDisappearedIsNamedWhenLoweringOrChangingOrRemovingByVoice(): void
    {
        // Given : la tarte est planifiée, puis la conversion de la plaquette de beurre disparaît
        $s = new ComposedScenario();
        $s->list();
        ($s->planned())($s->plan($s->pie));
        $before = $s->rows($s->reload());
        $without = new ConversionService(new ConversionInMemoryRepository([new ConversionBuilder()->isKiloToGramme()->build()]), new UnitInMemoryRepository());
        $serving = new UpdateServingMealHandler($s->lists, $s->recipes, $without, $s->typed->group, PartLines::for($s->ingredients, $without), FlatRows::for($s->recipes));
        $removal = new removeMealFromPlanHandler($s->lists, $s->recipes, $without, $s->typed->group, FlatRows::for($s->recipes));

        foreach ([
            'enlever des personnes' => fn() => (new RemoveSpokenMealHandler($s->lists, $s->recipes, $removal, $serving, $s->typed->group))(new RemoveSpokenMealCommand($s->owner->getId(), $s->pie->getId(), 1)),
            'enlever le repas' => fn() => (new RemoveSpokenMealHandler($s->lists, $s->recipes, $removal, $serving, $s->typed->group))(new RemoveSpokenMealCommand($s->owner->getId(), $s->pie->getId())),
            'changer le nombre de personnes' => fn() => (new ChangeSpokenMealServingsHandler($s->lists, $s->recipes, $serving, $s->typed->group))(new ChangeSpokenMealServingsCommand($s->owner->getId(), $s->pie->getId(), 8)),
        ] as $label => $gesture) {
            // When
            try {
                $gesture();
                $this->fail("$label : une conversion manquante refuse tout.");
            } catch (MealPlanningException $failure) {
                // Then
                $this->assertSame(['Tarte au citron', 'Beurre', 'Pâte sablée'], [$failure->recipeName, $failure->ingredientName, $failure->componentName], $label);
            }
        }
        $this->assertSame($before, $s->rows($s->reload()));
    }

    private function plan(ComposedScenario $s): PlanSpokenMealHandler
    {
        return new PlanSpokenMealHandler(
            $s->lists,
            $s->recipes,
            $s->planned(),
            new class implements DefaultServingCommandPort {
                public function of(string $userId): int
                {
                    return ComposedScenario::SERVING;
                }
            },
            new SpokenListName($s->typed->listTypes),
        );
    }

    private function removeMeal(ComposedScenario $s): RemoveSpokenMealHandler
    {
        return new RemoveSpokenMealHandler($s->lists, $s->recipes, $s->removal(), $s->serving(), $s->typed->group);
    }
}
