<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\HandOverCatalog\HandOverCatalogCommand;
use Repas\Repas\Application\Command\HandOverCatalog\HandOverCatalogHandler;
use Repas\Repas\Application\Command\HandOverCatalog\HandOverOutcome;
use Repas\Repas\Application\Service\AdoptionVerifier;
use Repas\Repas\Domain\Exception\IngredientCatalogException;
use Repas\Repas\Domain\Service\CatalogHandoverPreconditions;
use Repas\Shared\Domain\Event\RecipesOrIngredientsCreatedEvent;
use Repas\Tests\Helper\BaseIngredientClassificationInMemory;
use Repas\Tests\Helper\ControlledIngredientSeedingFeature;
use Repas\Tests\Helper\IngredientCatalogOwnerInMemory;
use Repas\Tests\Helper\InMemoryRepository\DriveIngredientReferencesInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientAdoptionBulkInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientCatalogHandoverInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\SpyEventDispatcher;

/**
 * La passation (phase 4, ING-009) sans base : les préconditions sont relues AVANT toute écriture et le message dit laquelle manque ; une fois
 * passées, les ingrédients sont au propriétaire, la statistique est annoncée UNE fois, un rejeu ne fait rien et n'annonce rien, et une empreinte
 * qui diverge fait échouer le cas d'usage (donc annule la transaction du bus). Le SQL et la transaction sont prouvés sur la vraie base.
 */
class HandOverCatalogHandlerTest extends TestCase
{
    private const string OWNER = 'owner-1';
    private const array FILE = ['beurre' => true, 'farine' => true, 'piment' => false];

    private IngredientCatalogHandoverInMemoryRepository $repository;
    private IngredientAdoptionBulkInMemoryRepository $bulk;
    private SpyEventDispatcher $events;

    protected function setUp(): void
    {
        $this->repository = new IngredientCatalogHandoverInMemoryRepository();
        $this->repository->withoutCreator(self::FILE);
        $this->bulk = new IngredientAdoptionBulkInMemoryRepository();
        $this->events = new SpyEventDispatcher();
    }

    public function testItHandsTheOldCommonsOverToTheOwnerAndAnnouncesTheStatisticOnce(): void
    {
        // Given : un état sain, l'interrupteur allumé
        $this->repository->ownConversions = 4;

        // When
        $result = $this->handler()(new HandOverCatalogCommand());

        // Then : les trois sont au propriétaire, avec leur marqueur, rien d'autre n'est écrit
        $this->assertSame(HandOverOutcome::HANDED_OVER, $result->outcome);
        $this->assertSame([3, 4], [$result->handedOver, $result->ownConversions]);
        $this->assertSame(['lock', 'state', 'assignToOwner'], $this->repository->calls);
        $this->assertSame(['creator' => self::OWNER, 'base' => true], $this->repository->ingredients['beurre']);
        $this->assertSame(['creator' => self::OWNER, 'base' => false], $this->repository->ingredients['piment']);
        $this->assertSame([], $this->repository->slugsWithoutCreator());
        // …et la statistique « ingrédients créés » du propriétaire est annoncée une seule fois, avec les trois slugs
        $this->assertCount(1, $this->events->eventDispatched);
        $event = $this->events->getLastEventDispatched();
        $this->assertInstanceOf(RecipesOrIngredientsCreatedEvent::class, $event);
        $this->assertSame(self::OWNER, $event->userId);
        $this->assertSame(['beurre', 'farine', 'piment'], array_values($event->ingredientSlugs->toArray()));
        $this->assertCount(0, $event->recipeIds);
    }

    public function testReplayingItWritesNothingAndAnnouncesNothing(): void
    {
        // Given : la passation est faite
        $handler = $this->handler();
        $handler(new HandOverCatalogCommand());
        $this->repository->calls = [];
        $this->events->eventDispatched = [];

        // When
        $result = $handler(new HandOverCatalogCommand());

        // Then
        $this->assertSame(HandOverOutcome::ALREADY_DONE, $result->outcome);
        $this->assertFalse($result->wroteSomething());
        $this->assertSame(['lock', 'state'], $this->repository->calls, 'aucune écriture');
        $this->assertSame([], $this->events->eventDispatched);
    }

    /**
     * @return iterable<string, array{callable(IngredientCatalogHandoverInMemoryRepository): void, bool, ?string, string}>
     */
    public static function unmetPreconditions(): iterable
    {
        yield 'P1 : le propriétaire n\'existe pas' => [static function (): void {}, true, null, 'P1'];
        yield 'P1 : le propriétaire est désactivé' => [static function (IngredientCatalogHandoverInMemoryRepository $r): void { $r->ownerStatus = 'DISABLED'; }, true, self::OWNER, 'P1'];
        yield 'P2 : une ligne d\'un autre compte' => [static function (IngredientCatalogHandoverInMemoryRepository $r): void { $r->foreignLinesByAccount = ['compte-a' => ['recettes' => 1]]; }, true, self::OWNER, 'P2'];
        yield 'P3 : un compte actif sans amorçage' => [static function (IngredientCatalogHandoverInMemoryRepository $r): void { $r->activeNotSeeded = ['compte-a']; }, true, self::OWNER, 'P3'];
        yield 'P4 : l\'interrupteur est coupé' => [static function (): void {}, false, self::OWNER, 'P4'];
        yield 'P5 : un commun hors fichier' => [static function (IngredientCatalogHandoverInMemoryRepository $r): void { $r->withoutCreator(['inconnu' => false]); }, true, self::OWNER, 'P5'];
        yield 'P5 : un ancien commun avec une origine' => [static function (IngredientCatalogHandoverInMemoryRepository $r): void { $r->withOrigin = 1; }, true, self::OWNER, 'P5'];
        yield 'P6 : une référence orpheline' => [static function (IngredientCatalogHandoverInMemoryRepository $r): void { $r->orphans = ['recettes' => 2]; }, true, self::OWNER, 'P6'];
        yield 'P7 : une ligne du propriétaire sur l\'ingrédient d\'un autre' => [static function (IngredientCatalogHandoverInMemoryRepository $r): void { $r->ownerLinesOnOthers = ['recettes' => 1]; }, true, self::OWNER, 'P7'];
    }

    /**
     * @param callable(IngredientCatalogHandoverInMemoryRepository): void $break
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unmetPreconditions')]
    public function testAMissingPreconditionStopsBeforeAnyWriteAndNamesItself(callable $break, bool $seedingEnabled, ?string $ownerId, string $code): void
    {
        // Given
        $break($this->repository);

        // When
        try {
            $this->handler($seedingEnabled, $ownerId)(new HandOverCatalogCommand());
            $this->fail('refus attendu');
        } catch (IngredientCatalogException $e) {
            // Then : le message dit laquelle manque, rien n'est écrit, aucune annonce
            $this->assertStringContainsString($code . ' :', $e->getMessage());
            $this->assertStringContainsString("rien n'est écrit", $e->getMessage());
            $this->assertNotContains('assignToOwner', $this->repository->calls);
            $this->assertSame([], $this->events->eventDispatched);
            $this->assertCount(3, $this->repository->slugsWithoutCreator() === [] ? [] : array_intersect($this->repository->slugsWithoutCreator(), ['beurre', 'farine', 'piment']), 'les trois sont toujours sans créateur');
        }
    }

    public function testAFingerprintThatDivergesFailsTheUseCaseAndSoRollsBackTheBusTransaction(): void
    {
        // Given : la base change le contenu d'un ingrédient pendant la passation
        $this->repository->corruptFingerprintAfterAssign = true;

        // Then
        $this->expectException(IngredientCatalogException::class);
        $this->expectExceptionMessage('le contenu des ingrédients ou de leurs conversions propres a changé');
        try {
            $this->handler()(new HandOverCatalogCommand());
        } finally {
            $this->assertSame([], $this->events->eventDispatched, 'rien n\'est annoncé');
        }
    }

    public function testAnUpdateThatTouchesAnotherNumberOfRowsFailsTheUseCase(): void
    {
        // Given
        $this->repository->assignedCount = 2;

        // Then
        $this->expectException(IngredientCatalogException::class);
        $this->expectExceptionMessage('2 ingrédient(s) passés, 3 attendus');
        $this->handler()(new HandOverCatalogCommand());
    }

    public function testTheOwnersVersionsAndSentListsMustNotMove(): void
    {
        // Given : le propriétaire a une recette et une liste ENVOYÉE sur ses anciens communs ; la base monte une version pendant la passation
        $this->bulk->ingredients = ['beurre' => ['name' => 'Beurre', 'department' => 'a', 'creator' => null, 'adoptedFrom' => null]];
        $this->bulk->recipes = ['recette-1' => ['author' => self::OWNER, 'version' => 3, 'rows' => [['ingredient' => 'beurre', 'quantity' => 1.5, 'unit' => 'gramme']]]];
        $this->bulk->lists = ['liste-1' => ['owner' => self::OWNER, 'version' => 5, 'status' => 'SENT', 'sentAt' => '2026-10-10 12:00:00', 'rows' => [['ingredient' => 'beurre', 'quantity' => 2.0, 'unit' => 'gramme']], 'ingredients' => []]];
        $bulk = $this->bulk;
        $this->repository->afterAssign = static function () use ($bulk): void {
            $bulk->recipes['recette-1']['version'] = 4;
        };

        // Then
        $this->expectException(IngredientCatalogException::class);
        $this->expectExceptionMessage('la version de 1');
        $this->handler()(new HandOverCatalogCommand());
    }

    private function handler(bool $seedingEnabled = true, ?string $ownerId = self::OWNER): HandOverCatalogHandler
    {
        return new HandOverCatalogHandler(
            $this->repository,
            new BaseIngredientClassificationInMemory(self::FILE),
            new IngredientCatalogOwnerInMemory($ownerId),
            new ControlledIngredientSeedingFeature($seedingEnabled),
            new CatalogHandoverPreconditions(),
            $this->bulk,
            new DriveIngredientReferencesInMemoryRepository(),
            new AdoptionVerifier(),
            $this->events,
        );
    }
}
