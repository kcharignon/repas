<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\RemoveRecipe\RemoveRecipeCommand;
use Repas\Repas\Application\Command\RemoveRecipe\RemoveRecipeHandler;
use Repas\Shared\Domain\Event\RecipeRemovedEvent;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\RecipeRepository;
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
    private readonly RecipeRepository $recipeRepository;
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
        $this->recipeRepository = new RecipeInMemoryRepository([$recipeFree, $recipeUsed, $neutral, $copy]);

        $this->shoppingListRepository = new ShoppingListInMemoryRepository([
            new ShoppingListBuilder()->withOwner($author)->addRecipe($recipeUsed)->build()
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
