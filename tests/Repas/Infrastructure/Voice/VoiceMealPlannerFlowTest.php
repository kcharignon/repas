<?php

namespace Repas\Tests\Repas\Infrastructure\Voice;

use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Meal;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Shared\Application\Dto\ChangedMealServings;
use Repas\Shared\Application\Dto\PlannedSpokenMeal;
use Repas\Shared\Application\Interface\VoiceMealPlanner;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Model\User;

/**
 * Le port `VoiceMealPlanner` câblé pour de vrai (ADR 0029 décision 13, VOX-305) : adaptateur, bus transactionnel, `PlanSpokenMealHandler`,
 * `PlannedMealHandler`, `UpdateServingMealHandler`, nombre de personnes du compte et repositories sur la base de test.
 */
class VoiceMealPlannerFlowTest extends DatabaseTestCase
{
    private VoiceMealPlanner $planner;
    private ShoppingListRepository $shoppingListRepository;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->planner = static::getContainer()->get(VoiceMealPlanner::class);
        $this->shoppingListRepository = static::getContainer()->get(ShoppingListRepository::class);
        $this->persister = new DataPersister(static::getContainer());
    }

    private function savedUser(int $serving = 3): User
    {
        $user = new UserBuilder()->withServing($serving)->build();
        $this->persister->saveUser($user);

        return $user;
    }

    /**
     * Une recette de `$author`, avec les conversions de ses ingrédients vers leur unité d'achat (comme l'application les attend).
     */
    private function savedRecipe(User $author, RecipeBuilder $builder, bool $withConversions = true): Recipe
    {
        $recipe = $builder->withAuthor($author)->build();
        foreach ($recipe->getRows() as $row) {
            $row->getIngredient()->setCreatorId($author->getId());
        }
        $this->persister->saveRecipe($recipe);
        if ($withConversions) {
            foreach ($recipe->getRows() as $row) {
                $purchaseUnit = $row->getIngredient()->getDefaultPurchaseUnit();
                if (!$row->getUnit()->isEqual($purchaseUnit)) {
                    $this->persister->saveConversion(new ConversionBuilder()->withIngredient($row->getIngredient())->withStartUnit($purchaseUnit)->withEndUnit($row->getUnit())->withCoefficient(100.0)->build());
                }
            }
        }

        return $recipe;
    }

    /**
     * @param list<Recipe> $recipes
     */
    private function savedList(User $owner, array $recipes, ShoppingListStatus $status = ShoppingListStatus::ACTIVE): ShoppingList
    {
        $builder = new ShoppingListBuilder()->withOwner($owner);
        foreach ($recipes as $recipe) {
            $builder->addRecipe($recipe);
        }
        $list = $builder->build();
        foreach ($recipes as $recipe) {
            foreach ($recipe->getRows() as $row) {
                $list->addRow($row->getIngredient()->getSlug(), $row->getIngredient()->getDefaultPurchaseUnit()->getSlug(), $row->getQuantity());
            }
        }
        if ($status === ShoppingListStatus::SENT) {
            $list->send(new \DateTimeImmutable());
        }
        $this->shoppingListRepository->save($list);

        return $list;
    }

    /**
     * Les quantités des lignes de la liste, relues en base, par ingrédient.
     *
     * @return array<string, float>
     */
    private function quantitiesOf(ShoppingList $list): array
    {
        $this->entityManager->clear();
        $quantities = [];
        foreach ($this->shoppingListRepository->findOneById($list->getId())->getRows() as $row) {
            $quantities[$row->getIngredientSlug()] = $row->getQuantity();
        }

        return $quantities;
    }

    private function mealOf(ShoppingList $list, Recipe $recipe): ?Meal
    {
        return $list->getMeals()->find(static fn(Meal $meal): bool => $meal->getRecipeId() === $recipe->getId());
    }

    public function testPlansTheMealInTheActiveListForTheServingsOfTheAccount(): void
    {
        // Given : un compte pour 3 personnes, une liste active avec une autre recette
        $user = $this->savedUser(3);
        $first = $this->savedRecipe($user, new RecipeBuilder()->isPastaCarbonara());
        $second = $this->savedRecipe($user, new RecipeBuilder()->isSoftBoiledEggs());
        $active = $this->savedList($user, [$first]);
        $this->entityManager->clear();

        // When
        $result = $this->planner->plan($user->getId(), $second->getId());
        $this->entityManager->clear();

        // Then : le repas est dans la liste, pour 3 personnes, dans le même exemplaire de liste
        $this->assertEquals(new PlannedSpokenMeal($second->getName(), 3, alreadyPlanned: false, listCreated: false, previousListSent: false), $result);
        $reloaded = $this->shoppingListRepository->findOneById($active->getId());
        $this->assertCount(2, $reloaded->getMeals());
        $this->assertSame(3, $this->mealOf($reloaded, $second)?->getServing());
        $this->assertCount(1, $this->shoppingListRepository->findByOwner($user->getId()));
    }

    public function testTheServingsSaidAreForced(): void
    {
        // Given
        $user = $this->savedUser(3);
        $recipe = $this->savedRecipe($user, new RecipeBuilder()->isPastaCarbonara());
        $active = $this->savedList($user, []);
        $this->entityManager->clear();

        // When
        $result = $this->planner->plan($user->getId(), $recipe->getId(), 6);
        $this->entityManager->clear();

        // Then
        $this->assertSame(6, $result->servings);
        $this->assertSame(6, $this->mealOf($this->shoppingListRepository->findOneById($active->getId()), $recipe)?->getServing());
    }

    public function testTheIngredientsOfTheRecipeAreAddedToTheLinesInTheRightProportions(): void
    {
        // Given : la même recette planifiée pour 2 puis, dans une autre liste, pour 4
        $user = $this->savedUser(2);
        $recipe = $this->savedRecipe($user, new RecipeBuilder()->isPastaCarbonara());
        $first = $this->savedList($user, []);
        $this->entityManager->clear();

        // When
        $this->planner->plan($user->getId(), $recipe->getId(), 2);
        $this->entityManager->clear();
        $forTwo = array_map(static fn($row): float => $row->getQuantity(), $this->shoppingListRepository->findOneById($first->getId())->getRows()->toArray());
        $this->planner->changeServings($user->getId(), $recipe->getId(), 4);
        $this->entityManager->clear();
        $forFour = array_map(static fn($row): float => $row->getQuantity(), $this->shoppingListRepository->findOneById($first->getId())->getRows()->toArray());

        // Then : quatre personnes demandent le double de deux
        $this->assertNotEmpty($forTwo);
        $this->assertEqualsWithDelta(array_map(static fn(float $quantity): float => $quantity * 2, array_values($forTwo)), array_values($forFour), 0.0001);
    }

    public function testCreatesTheActiveListWhenTheUserHasNone(): void
    {
        // Given
        $user = $this->savedUser(4);
        $recipe = $this->savedRecipe($user, new RecipeBuilder()->isPastaCarbonara());
        $this->entityManager->clear();

        // When
        $result = $this->planner->plan($user->getId(), $recipe->getId());
        $this->entityManager->clear();

        // Then
        $this->assertEquals(new PlannedSpokenMeal($recipe->getName(), 4, alreadyPlanned: false, listCreated: true, previousListSent: false), $result);
        $lists = $this->shoppingListRepository->findByOwner($user->getId());
        $this->assertCount(1, $lists);
        $this->assertSame(ShoppingListStatus::ACTIVE, $lists->reset()->getStatus());
        $this->assertSame(4, $this->mealOf($lists->reset(), $recipe)?->getServing());
    }

    public function testCreatesANewListWhenTheOnlyListIsSentAndLeavesItIntact(): void
    {
        // Given
        $user = $this->savedUser(4);
        $first = $this->savedRecipe($user, new RecipeBuilder()->isPastaCarbonara());
        $second = $this->savedRecipe($user, new RecipeBuilder()->isSoftBoiledEggs());
        $sent = $this->savedList($user, [$first], ShoppingListStatus::SENT);
        $this->entityManager->clear();

        // When
        $result = $this->planner->plan($user->getId(), $second->getId());
        $this->entityManager->clear();

        // Then : une liste neuve, la liste envoyée n'a pas bougé
        $this->assertEquals(new PlannedSpokenMeal($second->getName(), 4, alreadyPlanned: false, listCreated: true, previousListSent: true), $result);
        $reloaded = $this->shoppingListRepository->findOneById($sent->getId());
        $this->assertSame(ShoppingListStatus::SENT, $reloaded->getStatus());
        $this->assertSame($sent->getVersion(), $reloaded->getVersion());
        $this->assertCount(1, $reloaded->getMeals());
        $this->assertCount(2, $this->shoppingListRepository->findByOwner($user->getId()));
        $this->assertNotNull($this->shoppingListRepository->findOneActivateByOwner($user->getId()));
    }

    public function testARecipeAlreadyInTheListIsNeverPlannedTwiceAndNothingIsWritten(): void
    {
        // Given : elle y est déjà pour 3 (le défaut du constructeur de test)
        $user = $this->savedUser(4);
        $recipe = $this->savedRecipe($user, new RecipeBuilder()->isPastaCarbonara());
        $active = $this->savedList($user, [$recipe]);
        $existing = $this->mealOf($active, $recipe)?->getServing();
        $this->entityManager->clear();

        // When : même en demandant six
        $result = $this->planner->plan($user->getId(), $recipe->getId(), 6);
        $this->entityManager->clear();

        // Then : on dit pour combien elle y est ; ni liste de plus, ni repas de plus, ni version qui bouge
        $this->assertEquals(new PlannedSpokenMeal($recipe->getName(), $existing, alreadyPlanned: true, listCreated: false, previousListSent: false), $result);
        $reloaded = $this->shoppingListRepository->findOneById($active->getId());
        $this->assertCount(1, $reloaded->getMeals());
        $this->assertSame($active->getVersion(), $reloaded->getVersion());
        $this->assertCount(1, $this->shoppingListRepository->findByOwner($user->getId()));
    }

    public function testTheRecipeOfAnotherUserIsRefusedAndNothingIsWrittenNotEvenTheList(): void
    {
        // Given
        $user = $this->savedUser();
        $other = $this->savedUser();
        $theirs = $this->savedRecipe($other, new RecipeBuilder()->isPastaCarbonara());
        $this->entityManager->clear();

        // Then
        $this->expectExceptionObject(RecipeException::notFound($theirs->getId()));
        try {
            $this->planner->plan($user->getId(), $theirs->getId());
        } finally {
            $this->entityManager->clear();
            $this->assertCount(0, $this->shoppingListRepository->findByOwner($user->getId()));
        }
    }

    public function testAnUnknownRecipeIsRefused(): void
    {
        $user = $this->savedUser();

        $this->expectExceptionObject(RecipeException::notFound('does-not-exist'));
        $this->planner->plan($user->getId(), 'does-not-exist');
    }

    public function testServingsOutOfOneToNinetyNineAreRefusedAndNothingIsWritten(): void
    {
        // Given
        $user = $this->savedUser();
        $recipe = $this->savedRecipe($user, new RecipeBuilder()->isPastaCarbonara());
        $this->entityManager->clear();

        foreach ([0, 100, -3] as $servings) {
            // When
            try {
                $this->planner->plan($user->getId(), $recipe->getId(), $servings);
                $this->fail('Un nombre de personnes hors de 1 à 99 ne se planifie pas.');
            } catch (ShoppingListException $exception) {
                // Then
                $this->assertEquals(ShoppingListException::invalidSpokenServings($servings), $exception);
            }
        }
        $this->entityManager->clear();
        $this->assertCount(0, $this->shoppingListRepository->findByOwner($user->getId()));
    }

    public function testAnIngredientWithoutConversionFailsTheWholeCommandAndWritesNothing(): void
    {
        // Given : l'unité de la recette n'a aucune conversion vers l'unité d'achat de l'ingrédient
        $user = $this->savedUser();
        $recipe = $this->savedRecipe($user, new RecipeBuilder()->isPastaCarbonara(), withConversions: false);
        $this->entityManager->clear();

        // When
        try {
            $this->planner->plan($user->getId(), $recipe->getId());
            $this->fail('Une recette dont un ingrédient ne se convertit pas ne se planifie pas.');
        } catch (IngredientException) {
            // Then : comme dans l'application, tout est annulé (y compris la liste neuve)
            $this->entityManager->clear();
            $this->assertCount(0, $this->shoppingListRepository->findByOwner($user->getId()));
        }
    }

    public function testChangesTheTotalOfAMealAlreadyInTheList(): void
    {
        // Given
        $user = $this->savedUser(4);
        $recipe = $this->savedRecipe($user, new RecipeBuilder()->isPastaCarbonara());
        $active = $this->savedList($user, []);
        $this->entityManager->clear();
        $this->planner->plan($user->getId(), $recipe->getId(), 4);
        $this->entityManager->clear();

        $forFour = $this->quantitiesOf($active);

        // When : « six » puis « deux »
        $up = $this->planner->changeServings($user->getId(), $recipe->getId(), 6);
        $this->entityManager->clear();
        $afterUp = $this->mealOf($this->shoppingListRepository->findOneById($active->getId()), $recipe)?->getServing();
        $forSix = $this->quantitiesOf($active);
        $down = $this->planner->changeServings($user->getId(), $recipe->getId(), 2);
        $this->entityManager->clear();
        $forTwo = $this->quantitiesOf($active);

        // Then : le nouveau total remplace l'ancien, dans les deux sens
        $this->assertEquals(ChangedMealServings::changed($recipe->getName(), 6), $up);
        $this->assertSame(6, $afterUp);
        $this->assertEquals(ChangedMealServings::changed($recipe->getName(), 2), $down);
        $this->assertSame(2, $this->mealOf($this->shoppingListRepository->findOneById($active->getId()), $recipe)?->getServing());

        // Then : et les QUANTITÉS DES LIGNES ont suivi, la preuve de bout en bout (4 -> 6 -> 2, linéaire en nombre de personnes)
        $this->assertNotEmpty($forFour);
        $this->assertSame(array_keys($forFour), array_keys($forSix));
        $this->assertSame(array_keys($forFour), array_keys($forTwo));
        foreach ($forFour as $slug => $quantityForFour) {
            $this->assertGreaterThan(0.0, $quantityForFour, $slug);
            $this->assertEqualsWithDelta($quantityForFour * 6 / 4, $forSix[$slug], 0.0001, "$slug pour six");
            $this->assertEqualsWithDelta($quantityForFour * 2 / 4, $forTwo[$slug], 0.0001, "$slug pour deux");
        }
    }

    public function testChangingToTheSameTotalWritesNothing(): void
    {
        // Given
        $user = $this->savedUser(4);
        $recipe = $this->savedRecipe($user, new RecipeBuilder()->isPastaCarbonara());
        $active = $this->savedList($user, [$recipe]);
        $serving = $this->mealOf($active, $recipe)?->getServing();
        $this->entityManager->clear();

        // When
        $result = $this->planner->changeServings($user->getId(), $recipe->getId(), $serving);
        $this->entityManager->clear();

        // Then
        $this->assertEquals(ChangedMealServings::changed($recipe->getName(), $serving), $result);
        $this->assertSame($active->getVersion(), $this->shoppingListRepository->findOneById($active->getId())->getVersion());
    }

    public function testChangingTheTotalOfAMealThatIsNotInTheListSaysSoAndWritesNothing(): void
    {
        // Given : une liste sans ce repas, puis aucune liste du tout
        $user = $this->savedUser();
        $recipe = $this->savedRecipe($user, new RecipeBuilder()->isPastaCarbonara());
        $active = $this->savedList($user, []);
        $this->entityManager->clear();

        // When / Then
        $this->assertEquals(ChangedMealServings::notPlanned($recipe->getName()), $this->planner->changeServings($user->getId(), $recipe->getId(), 6));
        $this->entityManager->clear();
        $this->assertCount(0, $this->shoppingListRepository->findOneById($active->getId())->getMeals());

        $alone = $this->savedUser();
        $aloneRecipe = $this->savedRecipe($alone, new RecipeBuilder()->isSoftBoiledEggs());
        $this->assertEquals(ChangedMealServings::notPlanned($aloneRecipe->getName()), $this->planner->changeServings($alone->getId(), $aloneRecipe->getId(), 6));
        $this->assertCount(0, $this->shoppingListRepository->findByOwner($alone->getId()), 'changer un total ne crée jamais de liste');
    }

    public function testASentListIsNeverChanged(): void
    {
        // Given : le repas est dans la seule liste, envoyée au drive
        $user = $this->savedUser();
        $recipe = $this->savedRecipe($user, new RecipeBuilder()->isPastaCarbonara());
        $sent = $this->savedList($user, [$recipe], ShoppingListStatus::SENT);
        $this->entityManager->clear();

        // When
        $result = $this->planner->changeServings($user->getId(), $recipe->getId(), 6);
        $this->entityManager->clear();

        // Then : seule la liste ACTIVE compte, la liste envoyée reste telle quelle
        $this->assertEquals(ChangedMealServings::notPlanned($recipe->getName()), $result);
        $this->assertSame($sent->getVersion(), $this->shoppingListRepository->findOneById($sent->getId())->getVersion());
    }

    public function testChangingTheTotalOfTheRecipeOfAnotherUserIsRefused(): void
    {
        // Given
        $user = $this->savedUser();
        $other = $this->savedUser();
        $theirs = $this->savedRecipe($other, new RecipeBuilder()->isPastaCarbonara());
        $this->entityManager->clear();

        // Then
        $this->expectExceptionObject(RecipeException::notFound($theirs->getId()));
        $this->planner->changeServings($user->getId(), $theirs->getId(), 6);
    }

    public function testATotalOutOfOneToNinetyNineIsRefused(): void
    {
        $user = $this->savedUser();
        $recipe = $this->savedRecipe($user, new RecipeBuilder()->isPastaCarbonara());

        $this->expectExceptionObject(ShoppingListException::invalidSpokenServings(100));
        $this->planner->changeServings($user->getId(), $recipe->getId(), 100);
    }
}
