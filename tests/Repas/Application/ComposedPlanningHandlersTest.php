<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\PlannedMeal\PlannedMealHandler;
use Repas\Repas\Application\Command\RemoveMealFromPlan\removeMealFromPlanCommand;
use Repas\Repas\Application\Command\RemoveMealFromPlan\removeMealFromPlanHandler;
use Repas\Repas\Application\Command\UpdateServingMeal\UpdateServingMealCommand;
use Repas\Repas\Application\Command\UpdateServingMeal\UpdateServingMealHandler;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\ComposedScenario;
use Repas\Tests\Helper\FlatRows;
use Repas\Tests\Helper\InMemoryRepository\ConversionInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UnitInMemoryRepository;
use Repas\Tests\Helper\PartLines;

/**
 * Planifier, retirer et changer les personnes d'un repas dont la recette en utilise une autre (ADR 0040 décisions 11 à 16, COMP-004), avec les vrais
 * handlers sur des doubles en mémoire : les lignes de la tarte ET celles de sa pâte arrivent dans la liste, mises à l'échelle, et repartent exactement.
 *
 * Pour un repas de 4 personnes et une pâte « 1 fois » : 0,1 kg de sucre, 2 œufs (la tarte), 0,25 kg de farine, 0,5 plaquette de beurre (la pâte).
 */
class ComposedPlanningHandlersTest extends TestCase
{
    private const array PIE_AND_DOUGH = ['farine' => 0.25, 'sucre' => 0.1, 'beurre' => 0.5, 'oeuf' => 2.0];

    public function testPlanningAddsTheLinesOfThePieAndOfTheDough(): void
    {
        // Given
        $s = new ComposedScenario();
        $s->list();

        // When
        ($s->planned())($s->plan($s->pie));

        // Then : la tarte et la pâte, un seul repas (celui de la tarte)
        $list = $s->reload();
        $this->assertRows(self::PIE_AND_DOUGH, $s, $list);
        $this->assertCount(1, $list->getMeals());
        $this->assertNotNull($s->meal($list, $s->pie));
        $this->assertNull($s->meal($list, $s->dough), 'une recette utilisée n\'est jamais un repas de son parent');
    }

    public function testPlanningForMorePeopleScalesBothLevels(): void
    {
        // Given : la pâte « 2 fois », un repas de 8 personnes (k = 2) : sucre 0,2, œufs 4, farine 0,25 × 2 × 2 = 1, beurre 0,5 × 2 × 2 = 2
        $s = new ComposedScenario(multiplier: 2.0);
        $s->list();

        // When
        ($s->planned())($s->plan($s->pie, 8));

        // Then
        $this->assertRows(['farine' => 1.0, 'sucre' => 0.2, 'beurre' => 2.0, 'oeuf' => 4.0], $s, $s->reload());
    }

    public function testRemovingTheMealDeductsExactlyWhatWasAdded(): void
    {
        // Given
        $s = new ComposedScenario(multiplier: 1.5);
        $s->list();
        ($s->planned())($s->plan($s->pie, 7));
        $this->assertNotSame([], $s->rows($s->reload()));

        // When
        ($s->removal())(new removeMealFromPlanCommand($s->owner->getId(), $s->pie->getId()));

        // Then : la liste revient à vide, le repas est parti
        $list = $s->reload();
        $this->assertSame([], $s->rows($list));
        $this->assertCount(0, $list->getMeals());
    }

    public function testChangingThePeopleUpThenDownThenBackReturnsToTheSameLines(): void
    {
        // Given
        $s = new ComposedScenario(multiplier: 0.5);
        $s->list();
        ($s->planned())($s->plan($s->pie));
        $before = $s->rows($s->reload());
        $mealId = fn(): string => $s->meal($s->reload(), $s->pie)->getId();

        // When : 4 -> 6 : toutes les lignes, celles de la pâte comprises, x 1,5
        ($s->serving())(new UpdateServingMealCommand($mealId(), 6));
        $up = $s->rows($s->reload());

        // Then
        foreach ($before as $slug => $quantity) {
            $this->assertEqualsWithDelta($quantity * 1.5, $up[$slug], 1e-9, $slug);
        }

        // When : 6 -> 2 -> 4
        ($s->serving())(new UpdateServingMealCommand($mealId(), 2));
        $down = $s->rows($s->reload());
        ($s->serving())(new UpdateServingMealCommand($mealId(), 4));
        $back = $s->rows($s->reload());

        // Then
        foreach ($before as $slug => $quantity) {
            $this->assertEqualsWithDelta($quantity * 0.5, $down[$slug], 1e-9, $slug);
            $this->assertEqualsWithDelta($quantity, $back[$slug], 1e-9, $slug);
        }
        $this->assertSame(4, $s->meal($s->reload(), $s->pie)->getServing());

        // When : on retire le repas après ces allers-retours
        ($s->removal())(new removeMealFromPlanCommand($s->owner->getId(), $s->pie->getId()));

        // Then : rien ne reste
        $this->assertSame([], $s->rows($s->reload()));
    }

    public function testPlanningTheDoughAloneAndThePieAddsUpAndEachRemovalDeductsOnlyItsShare(): void
    {
        // Given : la pâte seule pour 6 (écrite pour 6 : 250 g de farine, 125 g de beurre), puis la tarte pour 4 (250 g de farine, 125 g de beurre aussi)
        $s = new ComposedScenario();
        $s->list();

        // When
        ($s->planned())($s->plan($s->dough, 6));
        $this->assertRows(['farine' => 0.25, 'beurre' => 0.5], $s, $s->reload());
        ($s->planned())($s->plan($s->pie));

        // Then : les quantités s'additionnent, deux repas
        $list = $s->reload();
        $this->assertRows(['farine' => 0.5, 'sucre' => 0.1, 'beurre' => 1.0, 'oeuf' => 2.0], $s, $list);
        $this->assertCount(2, $list->getMeals());

        // When : on retire la tarte
        ($s->removal())(new removeMealFromPlanCommand($s->owner->getId(), $s->pie->getId()));

        // Then : il reste la pâte seule
        $this->assertRows(['farine' => 0.25, 'beurre' => 0.5], $s, $s->reload());

        // When : on retire la pâte
        ($s->removal())(new removeMealFromPlanCommand($s->owner->getId(), $s->dough->getId()));

        // Then
        $this->assertSame([], $s->rows($s->reload()));
    }

    public function testPlanningTheDoughAloneAfterThePieIsStillAllowedAndRemovingThePieKeepsTheDoughShare(): void
    {
        // Given : la tarte d'abord, la pâte seule ensuite
        $s = new ComposedScenario();
        $s->list();
        ($s->planned())($s->plan($s->pie));
        ($s->planned())($s->plan($s->dough, 12));

        // When : la pâte seule pour 12 = deux fois ses lignes ; on retire la tarte
        ($s->removal())(new removeMealFromPlanCommand($s->owner->getId(), $s->pie->getId()));

        // Then : la pâte seule reste, intacte
        $this->assertRows(['farine' => 0.5, 'beurre' => 1.0], $s, $s->reload());
    }

    public function testASentListIsRefusedAndNothingIsWritten(): void
    {
        // Given : la tarte est planifiée, puis la liste part au drive
        $s = new ComposedScenario();
        $s->list();
        ($s->planned())($s->plan($s->pie));
        $list = $s->reload();
        $mealId = $s->meal($list, $s->pie)->getId();
        $list->send(new \DateTimeImmutable());
        $s->lists->save($list);

        // (une liste envoyée n'est plus « en cours » : le retrait ne la trouve pas, comme avant les recettes utilisées)
        foreach ([
            'changer les personnes' => [static fn() => ($s->serving())(new UpdateServingMealCommand($mealId, 8)), ShoppingListException::SENT],
            'retirer le repas' => [static fn() => ($s->removal())(new removeMealFromPlanCommand($s->owner->getId(), $s->pie->getId())), ShoppingListException::activeShoppingListNotFound()->getMessage()],
        ] as $label => [$gesture, $message]) {
            // When
            try {
                $gesture();
                $this->fail("$label : une liste envoyée au drive ne se modifie pas.");
            } catch (ShoppingListException $exception) {
                // Then
                $this->assertSame($message, $exception->getMessage(), $label);
            }
        }

        // Then : les lignes sont celles d'avant
        $after = $s->reload();
        $this->assertSame(ShoppingListStatus::SENT, $after->getStatus());
        $this->assertRows(self::PIE_AND_DOUGH, $s, $after);
        $this->assertSame(4, $s->meal($after, $s->pie)->getServing());
    }

    public function testAMissingConversionInTheDoughRefusesEverythingBeforeAnyWriteAndNamesTheDough(): void
    {
        // Given : la plaquette de beurre ne se convertit pas en grammes, le beurre est dans la PÂTE
        $s = new ComposedScenario(butterConversion: false);
        $s->list();

        // When
        try {
            ($s->planned())($s->plan($s->pie));
            $this->fail('Une conversion manquante refuse la planification.');
        } catch (IngredientException $exception) {
            // Then : l'ingrédient ET la recette utilisée sont nommés, le code d'avant ne change pas
            $this->assertTrue($exception->isMissingConversion());
            $this->assertSame('Beurre', $exception->conversionIngredientName());
            $this->assertSame('Pâte sablée', $exception->conversionRecipeName());
            $this->assertSame(422, $exception->getCode());
        }

        // Then : ni ligne, ni repas, avant la première écriture
        $list = $s->reload();
        $this->assertSame([], $s->rows($list));
        $this->assertCount(0, $list->getMeals());
    }

    public function testAMissingConversionOfThePieItselfNamesNoUsedRecipe(): void
    {
        // Given : le sucre de la TARTE ne se convertit pas (aucune conversion kilo > gramme) ; ses lignes passent avant celles de la pâte
        $s = new ComposedScenario();
        $s->list();
        $withoutKilo = new ConversionService(new ConversionInMemoryRepository([new ConversionBuilder()->isBlockToGrammeForButter()->build()]), new UnitInMemoryRepository());
        $planned = new PlannedMealHandler(
            $s->lists, $s->recipes, $withoutKilo, new UuidGenerator(), $s->typed->routing, $s->typed->activeListForType,
            PartLines::for($s->ingredients, $withoutKilo), FlatRows::for($s->recipes),
        );

        // When
        try {
            $planned($s->plan($s->pie));
            $this->fail('Une conversion manquante refuse la planification.');
        } catch (IngredientException $exception) {
            // Then : une ligne de la recette elle-même ne nomme aucune recette utilisée
            $this->assertSame(['Sucre', null], [$exception->conversionIngredientName(), $exception->conversionRecipeName()]);
        }
        $this->assertSame([], $s->rows($s->reload()));
    }

    public function testAConversionThatDisappearedRefusesAPeopleChangeAndARemovalNamingTheDoughAndWritesNothing(): void
    {
        // Given : la tarte est planifiée, puis la conversion de la plaquette de beurre disparaît
        $s = new ComposedScenario();
        $s->list();
        ($s->planned())($s->plan($s->pie));
        $mealId = $s->meal($s->reload(), $s->pie)->getId();
        $without = new ConversionService(new ConversionInMemoryRepository([new ConversionBuilder()->isKiloToGramme()->build()]), new UnitInMemoryRepository());
        $serving = new UpdateServingMealHandler($s->lists, $s->recipes, $without, $s->typed->group, PartLines::for($s->ingredients, $without), FlatRows::for($s->recipes));
        $removal = new removeMealFromPlanHandler($s->lists, $s->recipes, $without, $s->typed->group, FlatRows::for($s->recipes));

        foreach ([
            'augmenter' => static fn() => $serving(new UpdateServingMealCommand($mealId, 8)),
            'baisser' => static fn() => $serving(new UpdateServingMealCommand($mealId, 2)),
            'retirer' => static fn() => $removal(new removeMealFromPlanCommand($s->owner->getId(), $s->pie->getId())),
        ] as $label => $gesture) {
            // When
            try {
                $gesture();
                $this->fail("$label : une conversion manquante refuse tout.");
            } catch (IngredientException $exception) {
                // Then
                $this->assertSame(['Beurre', 'Pâte sablée'], [$exception->conversionIngredientName(), $exception->conversionRecipeName()], $label);
            }
        }

        // Then : rien n'a bougé
        $after = $s->reload();
        $this->assertRows(self::PIE_AND_DOUGH, $s, $after);
        $this->assertSame(4, $s->meal($after, $s->pie)->getServing());
    }

    public function testAPartOfAnIngredientInTheDoughFoldsIntoItsParentAndComesBackOut(): void
    {
        // Given : la pâte porte 3 jaunes d'œuf (une partie de l'œuf, ratio 1), la tarte 2 œufs
        $s = new ComposedScenario(yolkInDough: true);
        $s->list();

        // When : un repas de 4, la pâte « 1 fois » : 3 jaunes
        ($s->planned())($s->plan($s->pie));

        // Then : le jaune n'est jamais une ligne, la ligne de l'œuf porte le total : 2 + 3
        $list = $s->reload();
        $this->assertArrayNotHasKey('jaune', $s->rows($list));
        $this->assertEqualsWithDelta(5.0, $s->rows($list)['oeuf'], 1e-9);
        $this->assertNotNull($list->partShareOf('jaune'));

        // When : on ajoute 4 personnes (x 2) puis on retire le repas
        ($s->serving())(new UpdateServingMealCommand($s->meal($list, $s->pie)->getId(), 8));
        $this->assertEqualsWithDelta(10.0, $s->rows($s->reload())['oeuf'], 1e-9);
        ($s->removal())(new removeMealFromPlanCommand($s->owner->getId(), $s->pie->getId()));

        // Then : tout repart, le registre aussi
        $after = $s->reload();
        $this->assertSame([], $s->rows($after));
        $this->assertSame([], $after->getPartShares());
    }

    public function testARecipeSplitBetweenTwoListsSplitsTheLinesOfItsUsedRecipeToo(): void
    {
        // Given : le type « Boulangerie » tient le rayon de la farine (la pâte) et du sucre (la tarte) ; le beurre et les œufs restent dans la liste principale
        $s = new ComposedScenario(withBakery: true);
        $s->list('main');
        $s->list('bakery', $s->bakery->getId());

        // When
        ($s->planned())($s->plan($s->pie));

        // Then : la farine de la PÂTE est allée en boulangerie avec le sucre, le reste est dans la liste principale
        $main = $s->reload('main');
        $bakery = $s->reload('bakery');
        $this->assertSame(['beurre' => 0.5, 'oeuf' => 2.0], $s->rows($main));
        $this->assertSame(['farine' => 0.25, 'sucre' => 0.1], $s->rows($bakery));

        // Then : chaque repas se souvient des produits aplatis qu'il a posés, et les deux partagent un groupe
        $mainMeal = $s->meal($main, $s->pie);
        $bakeryMeal = $s->meal($bakery, $s->pie);
        $this->assertEqualsCanonicalizing(['beurre', 'oeuf'], $mainMeal->getPlacedIngredients());
        $this->assertEqualsCanonicalizing(['farine', 'sucre'], $bakeryMeal->getPlacedIngredients());
        $this->assertNotNull($mainMeal->getPlannedGroupId());
        $this->assertSame($mainMeal->getPlannedGroupId(), $bakeryMeal->getPlannedGroupId());

        // When : changer les personnes agit sur le groupe
        ($s->serving())(new UpdateServingMealCommand($mainMeal->getId(), 8));

        // Then
        $this->assertRows(['beurre' => 1.0, 'oeuf' => 4.0], $s, $s->reload('main'));
        $this->assertRows(['farine' => 0.5, 'sucre' => 0.2], $s, $s->reload('bakery'));

        // When : retirer la tarte retire le groupe
        ($s->removal())(new removeMealFromPlanCommand($s->owner->getId(), $s->pie->getId()));

        // Then
        $this->assertSame([], $s->rows($s->reload('main')));
        $this->assertSame([], $s->rows($s->reload('bakery')));
    }

    public function testARecipeWithoutAnyComponentReadsNoRecipeInBatch(): void
    {
        // Given : le gâteau de la tarte, sans composant
        $s = new ComposedScenario();
        $plain = new RecipeBuilder()->withId('plain-id')->withName('Cake')->withServing(4)->withAuthor($s->owner)
            ->addRow(new RecipeRowBuilder()->withIngredient($s->sugar)->withUnit(new UnitBuilder()->isGramme())->withQuantity(100))->build();
        $s->recipes->save($plain);
        $s->list();

        // When : planifier, changer les personnes, retirer
        ($s->planned())($s->plan($plain));
        ($s->serving())(new UpdateServingMealCommand($s->meal($s->reload(), $plain)->getId(), 6));
        ($s->removal())(new removeMealFromPlanCommand($s->owner->getId(), $plain->getId()));

        // Then : zéro composant, zéro lecture de plus
        $this->assertSame(0, $s->recipes->batchReads);
        $this->assertSame([], $s->rows($s->reload()));
    }

    public function testAComposedRecipeReadsItsUsedRecipesOnceWhateverTheirNumber(): void
    {
        // Given : une recette qui en utilise six
        $reads = [];
        foreach ([1, 6] as $count) {
            $s = new ComposedScenario();
            $parts = [];
            for ($i = 0; $i < $count; $i++) {
                $used = new RecipeBuilder()->withId("used-$i")->withName("Base $i")->withServing(2)->withAuthor($s->owner)
                    ->addRow(new RecipeRowBuilder()->withIngredient($s->flour)->withUnit(new UnitBuilder()->isGramme())->withQuantity(10))->build();
                $s->recipes->save($used);
                $parts[] = $used;
            }
            $big = new RecipeBuilder()->withId('big-id')->withName('Grande')->withServing(4)->withAuthor($s->owner)
                ->addRow(new RecipeRowBuilder()->withIngredient($s->sugar)->withUnit(new UnitBuilder()->isGramme())->withQuantity(100));
            foreach ($parts as $used) {
                $big->withComponent($used, 1.0);
            }
            $big = $big->build();
            $s->recipes->save($big);
            $s->list();
            $s->recipes->batchReads = 0;

            // When
            ($s->planned())($s->plan($big));
            ($s->serving())(new UpdateServingMealCommand($s->meal($s->reload(), $big)->getId(), 6));
            ($s->removal())(new removeMealFromPlanCommand($s->owner->getId(), $big->getId()));
            $reads[$count] = $this->batchReadsOf($s);

            // Then : tout repart
            $this->assertSame([], $s->rows($s->reload()), "$count recettes utilisées");
        }

        // Then : une lecture en lot par geste (3), que la recette en utilise une ou six
        $this->assertSame([1 => 3, 6 => 3], $reads);
    }

    public function testAnOrphanOrAForeignSecretComponentIsIgnoredNeverAnException(): void
    {
        // Given : la tarte utilise la pâte (supprimée) et une glace secrète d'un autre compte
        $s = new ComposedScenario();
        $secret = new RecipeBuilder()->withId('secret-id')->withName('Secrète')->withServing(2)->withAuthorId('someone-else')->asSecret()
            ->addRow(new RecipeRowBuilder()->withIngredient(new IngredientBuilder()->isButter()->withCreatorId('someone-else'))->withUnit(new UnitBuilder()->isGramme())->withQuantity(999))->build();
        $s->recipes->save($secret);
        $pie = new RecipeBuilder()->withId('pie-id')->withName('Tarte au citron')->withServing(4)->withAuthor($s->owner)
            ->addRow(new RecipeRowBuilder()->withIngredient($s->sugar)->withUnit(new UnitBuilder()->isGramme())->withQuantity(100))
            ->withComponent($s->dough, 1.0)->withComponent($secret, 1.0)->build();
        $s->recipes->save($pie);
        $s->recipes->delete($s->dough);
        $s->list();

        // When
        ($s->planned())($s->plan($pie));

        // Then : seulement le sucre de la tarte
        $this->assertRows(['sucre' => 0.1], $s, $s->reload());
    }

    public function testAModelLoadedWithoutItsEdgesIsReloadedSoNoIngredientIsMissing(): void
    {
        // Given : un modèle chargé en lot (sans ses arêtes) qui sait pourtant avoir un composant
        $s = new ComposedScenario();
        $withoutEdges = $s->recipes->findByIds([$s->pie->getId()])[$s->pie->getId()];
        $this->assertTrue($withoutEdges->hasComponents());
        $this->assertSame([], $withoutEdges->componentIds());
        $s->list();

        // When : le cas d'usage reçoit ce modèle (la commande vocale lui passe celui qu'elle a lu)
        $s->planned()->plan($withoutEdges, $s->plan($s->pie));

        // Then : la pâte n'est pas oubliée en silence
        $this->assertRows(self::PIE_AND_DOUGH, $s, $s->reload());
    }

    public function testPlanningThePieAFirstTimeAlsoWorksWhenTheUsedRecipeHasItsOwnComponentsTheyAreIgnored(): void
    {
        // Given : (donnée invalide) la pâte utilise elle-même une crème de beurre
        $s = new ComposedScenario();
        $cream = new RecipeBuilder()->withId('cream-id')->withName('Crème')->withServing(4)->withAuthor($s->owner)
            ->addRow(new RecipeRowBuilder()->withIngredient($s->egg)->withUnit(new UnitBuilder()->isUnite())->withQuantity(50))->build();
        $s->recipes->save($cream);
        $deepDough = new RecipeBuilder()->withId('dough-id')->withName('Pâte sablée')->withServing(6)->withAuthor($s->owner)
            ->addRow(new RecipeRowBuilder()->withIngredient($s->flour)->withUnit(new UnitBuilder()->isGramme())->withQuantity(250))
            ->withComponent($cream, 1.0)->build();
        $s->recipes->save($deepDough);
        $s->list();

        // When
        ($s->planned())($s->plan($s->pie));

        // Then : les œufs de la crème (profondeur 2) ne sont jamais lus, jamais de boucle
        $this->assertRows(['farine' => 0.25, 'sucre' => 0.1, 'oeuf' => 2.0], $s, $s->reload());
    }

    private function batchReadsOf(ComposedScenario $s): int
    {
        return $s->recipes->batchReads;
    }

    /**
     * @param array<string, float> $expected quantité attendue par ingrédient, et aucune autre ligne
     */
    private function assertRows(array $expected, ComposedScenario $s, ShoppingList $list): void
    {
        $actual = $s->rows($list);
        ksort($expected);
        $this->assertSame(array_keys($expected), array_keys($actual), 'les lignes de la liste');
        foreach ($expected as $slug => $quantity) {
            $this->assertEqualsWithDelta($quantity, $actual[$slug], 1e-9, $slug);
        }
    }
}
