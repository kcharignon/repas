<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\RemoveRecipe\RemoveRecipeCommand;
use Repas\Repas\Application\Command\RemoveRecipe\RemoveRecipeHandler;
use Repas\Shared\Domain\Event\RecipeRemovedEvent;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\RecipeInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\SpokenRecipePhraseChoiceInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\SpyEventDispatcher;
use Repas\Tests\Helper\MarmitonImportLockSpy;

class RemoveRecipeHandlerTest extends TestCase
{
    private readonly RemoveRecipeHandler $handler;
    private readonly RecipeInMemoryRepository $recipeRepository;
    private readonly ShoppingListRepository $shoppingListRepository;
    private readonly SpyEventDispatcher $eventDispatcher;
    private readonly MarmitonImportLockSpy $lock;
    private readonly SpokenRecipePhraseChoiceInMemoryRepository $spokenRecipePhraseRepository;

    protected function setUp(): void
    {
        $author = new UserBuilder()->withId('user-id');
        $recipeFree = new RecipeBuilder()->withId('recipe-free')->withAuthor($author)->isSoftBoiledEggs()->build();
        $recipeUsed = new RecipeBuilder()->withId('recipe-used')->withAuthor($author)->isSoftBoiledEggs()->build();
        // La recette sans propriétaire (la neutre d'une recette Marmiton) et la copie qu'en a faite l'utilisateur
        $neutral = new RecipeBuilder()->withId('neutral')->asMarmitonNeutral('22082')->isSoftBoiledEggs()->build();
        $copy = new RecipeBuilder()->withId('copy')->withAuthor($author)->withOriginalId('neutral')->withSourceUrl('https://www.marmiton.org/recettes/recette_tarte_22082.aspx')->isSoftBoiledEggs()->build();
        // Les recettes utilisées dans d'autres recettes (ADR 0040) : la tarte utilise la pâte et la crème ; la tarte « du soir » utilise la recette planifiée
        $dough = new RecipeBuilder()->withId('dough')->withAuthor($author)->isSoftBoiledEggs()->build();
        $cream = new RecipeBuilder()->withId('cream')->withAuthor($author)->isSoftBoiledEggs()->build();
        $pie = new RecipeBuilder()->withId('pie')->withAuthor($author)->isSoftBoiledEggs()->withComponent($dough, 2.0)->withComponent($cream, 0.5)->build();
        $both = new RecipeBuilder()->withId('both')->withAuthor($author)->isSoftBoiledEggs()->build();
        $evening = new RecipeBuilder()->withId('evening')->withAuthor($author)->isSoftBoiledEggs()->withComponent($both, 1.0)->build();
        $this->recipeRepository = new RecipeInMemoryRepository([$recipeFree, $recipeUsed, $neutral, $copy, $dough, $cream, $pie, $both, $evening]);

        $this->shoppingListRepository = new ShoppingListInMemoryRepository([
            new ShoppingListBuilder()->withOwner($author)->addRecipe($recipeUsed)->addRecipe($both)->build()
        ]);

        $this->eventDispatcher = new SpyEventDispatcher();
        $this->lock = new MarmitonImportLockSpy();

        $this->spokenRecipePhraseRepository = new SpokenRecipePhraseChoiceInMemoryRepository();
        $this->spokenRecipePhraseRepository->remember('user-id', 'oeufs', 'recipe-free', new \DateTimeImmutable());
        $this->spokenRecipePhraseRepository->remember('user-id', 'coque', 'recipe-used', new \DateTimeImmutable());

        $this->handler = new RemoveRecipeHandler(
            $this->recipeRepository,
            $this->shoppingListRepository,
            $this->eventDispatcher,
            $this->spokenRecipePhraseRepository,
            $this->lock,
        );
    }

    public function testSuccessfullyHandleRemoveRecipe(): void
    {
        // Arrange
        $command = new RemoveRecipeCommand('recipe-free');

        // Act
        ($this->handler)($command);

        // Assert
        $expectedEvent = new RecipeRemovedEvent('user-id', 'recipe-free');
        $actualEvent = $this->eventDispatcher->getLastEventDispatched();
        $this->assertEquals($expectedEvent, $actualEvent);

        $this->expectExceptionObject(RecipeException::notFound('recipe-free'));
        $this->recipeRepository->findOneById('recipe-free');
    }

    public function testWhatTheUserTaughtAlexaAboutTheRemovedRecipeIsForgottenAndTheRestKept(): void
    {
        // When
        ($this->handler)(new RemoveRecipeCommand('recipe-free'));

        // Then : la phrase de la recette supprimée part, celle d'une autre recette reste
        $this->assertSame([['user-id', 'coque']], array_map(static fn(array $row): array => [$row['userId'], $row['phrase']], $this->spokenRecipePhraseRepository->all()));
    }

    public function testAnAdministratorRemovesARecipeWithoutAnOwnerUnderTheLockOfItsMarmitonIdentifierAndNothingIsAnnounced(): void
    {
        // When
        ($this->handler)(new RemoveRecipeCommand('neutral', byAdministrator: true));

        // Then : le MÊME verrou qu'un import (un import concurrent attend la fin), aucune statistique ni phrase (le propriétaire réservé n'est pas un compte)
        $this->assertSame(['22082'], $this->lock->acquired);
        $this->assertSame([], $this->eventDispatcher->eventDispatched);
        $this->assertCount(2, $this->spokenRecipePhraseRepository->all());
        try {
            $this->recipeRepository->findOneById('neutral');
            $this->fail('La neutre aurait dû être supprimée.');
        } catch (RecipeException $exception) {
            $this->assertSame(404, $exception->getCode());
        }
    }

    public function testTheCopiesAlreadyMadeStayTheirAuthorsAsTheyAreWithTheirLinkOfOrigin(): void
    {
        // When
        ($this->handler)(new RemoveRecipeCommand('neutral', byAdministrator: true));

        // Then : la copie n'est ni supprimée ni modifiée
        $copy = $this->recipeRepository->findOneById('copy');
        $this->assertSame(['user-id', 'https://www.marmiton.org/recettes/recette_tarte_22082.aspx', 2], [$copy->getAuthorId(), $copy->getSourceUrl(), $copy->getRows()->count()]);
    }

    public function testAnOrdinaryUserCannotRemoveARecipeWithoutAnOwnerEvenIfTheRightWasForgotten(): void
    {
        // When & Then : sans `byAdministrator`, la garde du modèle refuse (403) et le verrou n'est pas pris
        try {
            ($this->handler)(new RemoveRecipeCommand('neutral'));
            $this->fail('La recette sans propriétaire est figée pour un utilisateur ordinaire.');
        } catch (RecipeException $exception) {
            $this->assertSame([RecipeException::MARMITON_FROZEN, 403], [$exception->getMessage(), $exception->getCode()]);
        }
        $this->assertSame([], $this->lock->acquired);
        $this->assertSame('neutral', $this->recipeRepository->findOneById('neutral')->getId());
    }

    public function testAnOrdinaryRecipeTakesNoMarmitonLockAndKeepsItsEvent(): void
    {
        // When : même par un administrateur, une recette ordinaire est une suppression ordinaire
        ($this->handler)(new RemoveRecipeCommand('recipe-free', byAdministrator: true));

        // Then
        $this->assertSame([], $this->lock->acquired);
        $this->assertEquals(new RecipeRemovedEvent('user-id', 'recipe-free'), $this->eventDispatcher->getLastEventDispatched());
    }

    public function testARecipeThatAnotherRecipeUsesCannotBeRemovedAndNothingHappens(): void
    {
        // When & Then : `409 RECIPE_USED_AS_COMPONENT` (ADR 0040 décision 31)
        try {
            ($this->handler)(new RemoveRecipeCommand('dough'));
            $this->fail('Une recette utilisée ne se supprime pas.');
        } catch (RecipeException $exception) {
            $this->assertSame([RecipeException::USED_AS_COMPONENT, 409], [$exception->getMessage(), $exception->getCode()]);
        }
        $this->assertSame('dough', $this->recipeRepository->findOneById('dough')->getId());
        $this->assertSame([], $this->eventDispatcher->eventDispatched);
        $this->assertCount(2, $this->spokenRecipePhraseRepository->all());
    }

    public function testTheRefusalOfAUsedRecipeComesBeforeTheOneOfTheShoppingLists(): void
    {
        // When & Then : la recette « both » est planifiée ET utilisée par « evening » : le refus de l'usage vient d'abord
        try {
            ($this->handler)(new RemoveRecipeCommand('both'));
            $this->fail('Une recette utilisée ne se supprime pas.');
        } catch (RecipeException $exception) {
            $this->assertSame(RecipeException::USED_AS_COMPONENT, $exception->getMessage());
        }
    }

    public function testAComposedRecipeIsRemovedAndTheRecipesItUsedStayIntact(): void
    {
        // When
        ($this->handler)(new RemoveRecipeCommand('pie'));

        // Then : la tarte est partie (ses arêtes avec elle, dans le dépôt), la pâte et la crème restent ; l'événement des statistiques est celui d'une suppression ordinaire
        $this->assertEquals(new RecipeRemovedEvent('user-id', 'pie'), $this->eventDispatcher->getLastEventDispatched());
        $this->assertSame(['dough', 'cream'], array_map(fn(string $id): string => $this->recipeRepository->findOneById($id)->getId(), ['dough', 'cream']));
        try {
            $this->recipeRepository->findOneById('pie');
            $this->fail('La tarte aurait dû être supprimée.');
        } catch (RecipeException $exception) {
            $this->assertSame(404, $exception->getCode());
        }

        // When : la pâte n'est plus utilisée par personne, elle se supprime
        ($this->handler)(new RemoveRecipeCommand('dough'));
        $this->assertEquals(new RecipeRemovedEvent('user-id', 'dough'), $this->eventDispatcher->getLastEventDispatched());
    }

    public function testTheQuestionIsAskedOnceForAnOrdinaryRecipeAndNeverForARecipeWithoutAnOwner(): void
    {
        // When
        ($this->handler)(new RemoveRecipeCommand('recipe-free'));
        $afterOrdinary = $this->recipeRepository->usedAsComponentChecks;
        ($this->handler)(new RemoveRecipeCommand('neutral', byAdministrator: true));

        // Then : une requête d'existence pour la recette ordinaire, aucune pour la recette sans propriétaire (jamais utilisée)
        $this->assertSame([1, 1], [$afterOrdinary, $this->recipeRepository->usedAsComponentChecks]);
    }

    public function testFailedHandleRemoveRecipeAlreadyUsedInShoppingList(): void
    {
        // Arrange
        $command = new RemoveRecipeCommand('recipe-used');

        // Assert
        $this->expectExceptionObject(RecipeException::cannotRemoveExistInShoppingList('recipe-used'));

        // Act
        ($this->handler)($command);
    }

    public function testRefusesAnOutdatedVersionAndRemovesNothing(): void
    {
        // Arrange : la recette est à la version 1, le client croit à la 2
        $command = new RemoveRecipeCommand('recipe-free', expectedVersion: 2);

        // Assert
        $this->expectExceptionObject(RecipeException::outdated('recipe-free'));

        // Act
        try {
            ($this->handler)($command);
        } finally {
            $this->assertSame('recipe-free', $this->recipeRepository->findOneById('recipe-free')->getId());
            $this->assertSame([], $this->eventDispatcher->eventDispatched);
        }
    }
}
