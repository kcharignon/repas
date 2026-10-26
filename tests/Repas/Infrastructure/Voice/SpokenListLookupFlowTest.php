<?php

namespace Repas\Tests\Repas\Infrastructure\Voice;

use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Shared\Application\Interface\SpokenListLookup;
use Repas\Shared\Application\Interface\VoiceShoppingList;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Model\User;

/**
 * Le port `SpokenListLookup` câblé pour de vrai (ADR 0029 décision 12, VOX-304) : adaptateur, chargeur de la liste active, chargeur des habitudes,
 * mémoire de phrase et classeur sur la base de test. Les ex æquo se prouvent dans `SpokenListLookupAdapterTest`, avec des candidats programmés.
 */
class SpokenListLookupFlowTest extends DatabaseTestCase
{
    private SpokenListLookup $lookup;
    private VoiceShoppingList $voiceShoppingList;
    private ShoppingListRepository $shoppingListRepository;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lookup = static::getContainer()->get(SpokenListLookup::class);
        $this->voiceShoppingList = static::getContainer()->get(VoiceShoppingList::class);
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
        $found = $this->lookup->find($user->getId(), 'du lait');
        $absent = $this->lookup->find($user->getId(), 'du pain');
        $unknown = $this->lookup->find($user->getId(), 'de la ciboulette');

        // Then : la ligne (quantité et unité), puis « pas dans la liste » avec le nom connu, puis sans nom
        $this->assertTrue($found->hasList);
        $this->assertCount(1, $found->lines);
        $this->assertSame([$milk->getSlug(), 3.0, 'litre'], [$found->lines[0]->slug, $found->lines[0]->quantity, $found->lines[0]->unitName]);
        $this->assertSame([true, [], 'pain'], [$absent->hasList, $absent->lines, $absent->absentName]);
        $this->assertSame([true, [], null], [$unknown->hasList, $unknown->lines, $unknown->absentName]);
    }

    public function testWithoutAnActiveListThereIsNoList(): void
    {
        // Given : pas de liste du tout, puis une liste partie au drive
        $user = $this->savedUser();
        $milk = new IngredientBuilder()->isMilk()->withCreator($user, true)->build();
        $this->persister->saveIngredient($milk);
        $none = $this->lookup->find($user->getId(), 'du lait');
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withOwner($user)->addIngredient($milk)->withStatus(ShoppingListStatus::SENT)->build());
        $this->entityManager->clear();

        // When
        $sent = $this->lookup->find($user->getId(), 'du lait');

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
        $result = $this->lookup->find($user->getId(), 'du lait');

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
        $found = $this->lookup->findMeal($user->getId(), $carbonara->getName());
        $notPlanned = $this->lookup->findMeal($user->getId(), $eggs->getName());

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
        $result = $this->lookup->findMeal($user->getId(), $theirs->getName());

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
        $none = $this->lookup->findMeal($user->getId(), $recipe->getName());
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withOwner($user)->addRecipe($recipe)->withStatus(ShoppingListStatus::SENT)->build());
        $this->entityManager->clear();

        // When
        $sent = $this->lookup->findMeal($user->getId(), $recipe->getName());

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
        $unrelated = $this->lookup->find($user->getId(), 'de la ciboulette');
        $exact = $this->lookup->find($user->getId(), 'du lait');

        // Then : aucune proposition pour un mot sans rapport, la ligne pour le nom exact
        $this->assertSame([], $unrelated->suggestions);
        $this->assertNull($unrelated->absentName);
        $this->assertSame([$milk->getSlug()], array_map(static fn($line): string => $line->slug, $exact->lines));
    }
}
