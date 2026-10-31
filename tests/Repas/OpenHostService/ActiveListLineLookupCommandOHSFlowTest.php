<?php

namespace Repas\Tests\Repas\OpenHostService;

use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Repas\Application\OpenHostService\ActiveListLineLookupCommandOHS;
use Repas\Repas\Application\OpenHostService\ActiveListLineLookupQueryOHS;
use Repas\Repas\Application\OpenHostService\ActiveListMealLookupCommandOHS;
use Repas\Repas\Application\OpenHostService\ShoppingListItemCommandOHS;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Model\User;

/**
 * Les Open Host Services de la liste active câblés pour de vrai (ADR 0029 décision 12, VOX-304) : lecture de la liste active et des habitudes,
 * mémoire de phrase et classeur sur la base de test, par les dépôts (écriture) comme par les Loaders (lecture). Les ex æquo se prouvent dans `ActiveListLookupCommandOHSTest`, avec des candidats programmés.
 */
class ActiveListLineLookupCommandOHSFlowTest extends DatabaseTestCase
{
    private ActiveListLineLookupCommandOHS $lookup;
    private ActiveListMealLookupCommandOHS $meals;
    private ActiveListLineLookupQueryOHS $query;
    private ShoppingListItemCommandOHS $voiceShoppingList;
    private ShoppingListRepository $shoppingListRepository;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lookup = static::getContainer()->get(ActiveListLineLookupCommandOHS::class);
        $this->meals = static::getContainer()->get(ActiveListMealLookupCommandOHS::class);
        $this->query = static::getContainer()->get(ActiveListLineLookupQueryOHS::class);
        $this->voiceShoppingList = static::getContainer()->get(ShoppingListItemCommandOHS::class);
        $this->shoppingListRepository = static::getContainer()->get(ShoppingListRepository::class);
        $this->persister = new DataPersister(static::getContainer());
    }

    private function savedUser(): User
    {
        $user = new UserBuilder()->build();
        $this->persister->saveUser($user);

        return $user;
    }

    public function testASpokenPhraseFindsTheLineOfTheIngredientInTheActiveList(): void
    {
        // Given : trois litres de lait dans la liste, du pain connu mais absent
        $user = $this->savedUser();
        $milk = new IngredientBuilder()->isMilk()->withCreator($user, true)->build();
        $this->persister->saveIngredient($milk);
        $this->persister->saveIngredient(new IngredientBuilder()->isBread()->withCreator($user, true)->build());
        $this->voiceShoppingList->addItem($user->getId(), $milk->getSlug(), 3);
        $this->entityManager->clear();

        // When
        $found = $this->lookup->findLine($user->getId(), 'du lait');
        $absent = $this->lookup->findLine($user->getId(), 'du pain');
        $unknown = $this->lookup->findLine($user->getId(), 'de la ciboulette');

        // Then : la ligne (quantité et unité), puis « pas dans la liste » avec le nom connu, puis sans nom
        $this->assertTrue($found->hasList);
        $this->assertCount(1, $found->lines);
        $this->assertSame([$milk->getSlug(), 3.0, 'litre'], [$found->lines[0]->slug, $found->lines[0]->quantity, $found->lines[0]->unitName]);
        $this->assertSame([true, [], 'Pain'], [$absent->hasList, $absent->lines, $absent->absentName]);
        $this->assertSame([true, [], null], [$unknown->hasList, $unknown->lines, $unknown->absentName]);
    }

    public function testWithoutAnActiveListThereIsNoList(): void
    {
        // Given : pas de liste du tout, puis une liste partie au drive
        $user = $this->savedUser();
        $milk = new IngredientBuilder()->isMilk()->withCreator($user, true)->build();
        $this->persister->saveIngredient($milk);
        $none = $this->lookup->findLine($user->getId(), 'du lait');
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withOwner($user)->addIngredient($milk)->withStatus(ShoppingListStatus::SENT)->build());
        $this->entityManager->clear();

        // When
        $sent = $this->lookup->findLine($user->getId(), 'du lait');

        // Then : aucune des deux n'est « une liste en cours », et rien n'a été créé
        $this->assertFalse($none->hasList);
        $this->assertFalse($sent->hasList);
        $this->assertCount(1, $this->shoppingListRepository->findByOwner($user->getId()));
    }

    public function testTheListOfAnotherUserIsNeverSeen(): void
    {
        // Given : le lait est dans la liste d'un autre, l'utilisateur a une liste vide
        $user = $this->savedUser();
        $other = $this->savedUser();
        $theirMilk = new IngredientBuilder()->isMilk()->withCreator($other, true)->build();
        $this->persister->saveIngredient($theirMilk);
        $this->voiceShoppingList->addItem($other->getId(), $theirMilk->getSlug());
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withOwner($user)->build());
        $this->entityManager->clear();

        // When
        $result = $this->lookup->findLine($user->getId(), 'du lait');

        // Then
        $this->assertSame([true, [], null], [$result->hasList, $result->lines, $result->absentName]);
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Les repas planifiés et les propositions de la liste (ADR 0029 décision 12, VOX-306)
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    private function savedRecipe(User $author, RecipeBuilder $builder): Recipe
    {
        $recipe = $builder->withAuthor($author)->build();
        foreach ($recipe->getRows() as $row) {
            $row->getIngredient()->setCreatorId($author->getId());
        }
        $this->persister->saveRecipe($recipe);

        return $recipe;
    }

    public function testASpokenPhraseFindsThePlannedMealButNeverARecipeThatIsNotInTheList(): void
    {
        // Given : la carbonara est planifiée, les œufs mollets sont une recette de l'utilisateur mais ne sont pas dans la liste
        $user = $this->savedUser();
        $carbonara = $this->savedRecipe($user, new RecipeBuilder()->isPastaCarbonara());
        $eggs = $this->savedRecipe($user, new RecipeBuilder()->isSoftBoiledEggs());
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withOwner($user)->addRecipe($carbonara)->build());
        $this->entityManager->clear();

        // When
        $found = $this->meals->findMeal($user->getId(), $carbonara->getName());
        $notPlanned = $this->meals->findMeal($user->getId(), $eggs->getName());

        // Then : le repas de la liste, avec son nombre de personnes ; la recette qui n'y est pas n'est ni trouvée ni proposée
        $this->assertTrue($found->hasList);
        $this->assertSame([$carbonara->getId(), $carbonara->getName()], [$found->meal?->recipeId, $found->meal?->name]);
        $this->assertSame($user->getDefaultServing(), $found->meal?->servings);
        $this->assertTrue($notPlanned->hasList);
        $this->assertNull($notPlanned->meal);
        $this->assertSame([], $notPlanned->suggestions);
    }

    public function testTheMealsOfAnotherUserAreNeverFound(): void
    {
        // Given : le repas est dans la liste d'un autre compte
        $user = $this->savedUser();
        $other = $this->savedUser();
        $theirs = $this->savedRecipe($other, new RecipeBuilder()->isPastaCarbonara());
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withOwner($other)->addRecipe($theirs)->build());
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withOwner($user)->build());
        $this->entityManager->clear();

        // When
        $result = $this->meals->findMeal($user->getId(), $theirs->getName());

        // Then
        $this->assertTrue($result->hasList);
        $this->assertNull($result->meal);
        $this->assertSame([], $result->suggestions);
    }

    public function testWithoutAnActiveListThereIsNoMealAndASentListIsSaidApart(): void
    {
        // Given : pas de liste du tout, puis une liste partie au drive qui porte le repas
        $user = $this->savedUser();
        $recipe = $this->savedRecipe($user, new RecipeBuilder()->isPastaCarbonara());
        $none = $this->meals->findMeal($user->getId(), $recipe->getName());
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withOwner($user)->addRecipe($recipe)->withStatus(ShoppingListStatus::SENT)->build());
        $this->entityManager->clear();

        // When
        $sent = $this->meals->findMeal($user->getId(), $recipe->getName());

        // Then : la liste envoyée n'est jamais lue
        $this->assertSame([false, false, null], [$none->hasList, $none->listSent, $none->meal]);
        $this->assertSame([false, true, null], [$sent->hasList, $sent->listSent, $sent->meal]);
    }

    public function testAPhraseThatLooksLikeNoIngredientProposesTheLinesOfTheListThatLookLikeIt(): void
    {
        // Given : la liste porte du lait ; la phrase dite ressemble à du lait sans en être un nom
        $user = $this->savedUser();
        $milk = new IngredientBuilder()->isMilk()->withCreator($user, true)->build();
        $this->persister->saveIngredient($milk);
        $this->voiceShoppingList->addItem($user->getId(), $milk->getSlug(), 2);
        $this->entityManager->clear();

        // When : un mot sans rapport, puis le nom exact
        $unrelated = $this->lookup->findLine($user->getId(), 'de la ciboulette');
        $exact = $this->lookup->findLine($user->getId(), 'du lait');

        // Then : aucune proposition pour un mot sans rapport, la ligne pour le nom exact
        $this->assertSame([], $unrelated->suggestions);
        $this->assertNull($unrelated->absentName);
        $this->assertSame([$milk->getSlug()], array_map(static fn($line): string => $line->slug, $exact->lines));
    }

    public function testTheReadingSideSaysTheSameAsTheWritingSide(): void
    {
        // Given : trois litres de lait dans la liste, du pain connu mais absent
        $user = $this->savedUser();
        $milk = new IngredientBuilder()->isMilk()->withCreator($user, true)->build();
        $this->persister->saveIngredient($milk);
        $this->persister->saveIngredient(new IngredientBuilder()->isBread()->withCreator($user, true)->build());
        $this->voiceShoppingList->addItem($user->getId(), $milk->getSlug(), 3);
        $this->entityManager->clear();

        foreach (['du lait', 'du pain', 'de la ciboulette'] as $phrase) {
            // When
            $written = $this->lookup->findLine($user->getId(), $phrase);
            $read = $this->query->findLine($user->getId(), $phrase);

            // Then
            $this->assertSame([$written->hasList, $written->absentName, $written->listSent, count($written->lines)], [$read->hasList, $read->absentName, $read->listSent, count($read->lines)], $phrase);
        }
        $milkLine = $this->query->findLine($user->getId(), 'du lait')->lines[0];
        $this->assertSame([$milk->getSlug(), 3.0, 'litre'], [$milkLine->slug, $milkLine->quantity, $milkLine->unitName]);
    }
}
