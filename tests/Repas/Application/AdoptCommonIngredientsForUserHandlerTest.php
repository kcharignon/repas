<?php

namespace Repas\Tests\Repas\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\AdoptCommonIngredientsForUser\AdoptCommonIngredientsForUserCommand;
use Repas\Repas\Application\Command\AdoptCommonIngredientsForUser\AdoptCommonIngredientsForUserHandler;
use Repas\Repas\Application\Command\AdoptCommonIngredientsForUser\AdoptionResult;
use Repas\Repas\Application\Command\UndoCommonIngredientsAdoptionForUser\UndoCommonIngredientsAdoptionForUserHandler;
use Repas\Repas\Application\Service\AdoptionVerifier;
use Repas\Repas\Domain\Exception\IngredientAdoptionException;
use Repas\Repas\Domain\Service\IngredientSlugPolicy;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\DriveIngredientReferencesInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientAdoptionBulkInMemoryRepository;
use Repas\Shared\Domain\Interface\UuidGenerator;

/**
 * L'orchestration de la migration d'adoption pour UN compte (ING-003), sans base : périmètre, collision de slug, idempotence, listes
 * envoyées, empreintes qui divergent, aucun événement. Le SQL est prouvé sur la vraie base ; la transaction, par les tests de la commande.
 *
 * Le handler n'a ni répartiteur d'événements ni gestionnaire de statistiques dans ses dépendances : une adoption n'est pas une création.
 */
class AdoptCommonIngredientsForUserHandlerTest extends TestCase
{
    private const string USER = 'user-1';
    private const string NOW = '2026-10-21 03:00:00';

    private IngredientAdoptionBulkInMemoryRepository $bulk;
    private DriveIngredientReferencesInMemoryRepository $drive;
    private AdoptCommonIngredientsForUserHandler $handler;

    protected function setUp(): void
    {
        $this->bulk = new IngredientAdoptionBulkInMemoryRepository();
        $this->drive = new DriveIngredientReferencesInMemoryRepository();
        $this->handler = $this->handlerWith($this->bulk, $this->drive);

        // Le catalogue : trois communs, dont le beurre qui a une conversion propre
        foreach (['beurre' => 'Beurre', 'farine' => 'Farine', 'tomate' => 'Tomate'] as $slug => $name) {
            $this->bulk->ingredients[$slug] = ['name' => $name, 'department' => 'rayon', 'creator' => null, 'adoptedFrom' => null];
        }
        $this->bulk->conversions = [['id' => 'conversion-beurre', 'ingredient' => 'beurre'], ['id' => 'conversion-commune', 'ingredient' => null]];
    }

    public function testTheMigrationHandlersEmitNoEventAndHaveNoWayToDoIt(): void
    {
        // Une adoption n'est pas une création (ADR 0028 décision 7) : aucun répartiteur d'événements dans les dépendances des handlers
        foreach ([AdoptCommonIngredientsForUserHandler::class, UndoCommonIngredientsAdoptionForUserHandler::class] as $handler) {
            $types = array_map(
                static fn(\ReflectionParameter $parameter): string => (string) $parameter->getType(),
                (new \ReflectionClass($handler))->getConstructor()->getParameters(),
            );
            $this->assertSame([], array_values(array_filter($types, static fn(string $type): bool => str_contains($type, 'EventDispatcher') || str_contains($type, 'MessageBus') || str_contains($type, 'CommandBus'))), $handler);
        }
    }

    public function testAnAdministratorIsNotConcernedAndNothingIsWritten(): void
    {
        // Given : un administrateur avec des recettes
        $this->bulk->administrators[] = self::USER;
        $this->recipe('r1', self::USER, ['beurre']);

        // When
        $result = ($this->handler)(new AdoptCommonIngredientsForUserCommand(self::USER, 'migration-test'));

        // Then
        $this->assertFalse($result->concerned);
        $this->assertSame([], $this->bulk->aliases);
        $this->assertSame(['beurre'], array_column($this->bulk->recipes['r1']['rows'], 'ingredient'));
        $this->assertSame(['lock'], $this->bulk->calls);
    }

    public function testAnAccountWithNeitherRecipeNorPersonalIngredientReceivesNothing(): void
    {
        // Given : un compte qui n'a qu'une liste et un choix du drive
        $this->list('l1', self::USER, 'ACTIVE', ['beurre']);
        $this->drive->choices['c1'] = ['user' => self::USER, 'slug' => 'beurre', 'products' => 2];

        // When
        $result = ($this->handler)(new AdoptCommonIngredientsForUserCommand(self::USER, 'migration-test'));

        // Then : il garde les communs partagés, strictement rien n'est modifié
        $this->assertEquals(AdoptionResult::notConcerned(), $result);
        $this->assertSame([], $this->bulk->aliases);
        $this->assertSame(['beurre'], array_column($this->bulk->lists['l1']['rows'], 'ingredient'));
        $this->assertSame(5, $this->bulk->lists['l1']['version']);
        $this->assertSame('beurre', $this->slugOfChoice('c1'));
        $this->assertSame([], $this->drive->repointCalls);
    }

    public function testEveryCommonIsAdoptedAndEveryReferenceIsRepointedWithoutLosingAnything(): void
    {
        // Given : une recette, une liste active et une liste envoyée sur les communs, un choix du drive, un ingrédient personnel
        $this->personal('farine' . self::USER, 'Autre farine');
        $this->recipe('r1', self::USER, ['beurre', 'farine', 'farine' . self::USER]);
        $this->list('l1', self::USER, 'ACTIVE', ['beurre'], ['farine']);
        $this->list('l2', self::USER, 'SENT', ['tomate']);
        $this->drive->choices['c1'] = ['user' => self::USER, 'slug' => 'beurre', 'products' => 3];
        $this->drive->choices['c2'] = ['user' => self::USER, 'slug' => 'farine' . self::USER, 'products' => 1];
        $before = $this->bulk->snapshot(self::USER)->contentFingerprint();

        // When
        $result = ($this->handler)(new AdoptCommonIngredientsForUserCommand(self::USER, 'migration-test', acceptSent: true));

        // Then : trois copies, une conversion propre, des alias valides, tout repointé
        $this->assertTrue($result->concerned);
        $this->assertSame([3, 1, 1], [$result->adopted, $result->conversionsCopied, $result->slugCollisions]);
        $this->assertSame(['beurre', 'farine', 'tomate'], array_keys($this->bulk->aliases[self::USER]));
        $this->assertSame('beurre' . self::USER, $this->bulk->aliases[self::USER]['beurre']['ingredient']);
        $this->assertSame('migration-test', $this->bulk->aliases[self::USER]['beurre']['source']);
        $this->assertSame(
            ['name' => 'Beurre', 'department' => 'rayon', 'creator' => self::USER, 'adoptedFrom' => 'beurre'],
            $this->bulk->ingredients['beurre' . self::USER],
        );
        $this->assertSame(['beurre' . self::USER, 'farine-2' . self::USER, 'farine' . self::USER], array_column($this->bulk->recipes['r1']['rows'], 'ingredient'));
        $this->assertSame(['beurre' . self::USER], array_column($this->bulk->lists['l1']['rows'], 'ingredient'));
        $this->assertSame(['farine-2' . self::USER], array_column($this->bulk->lists['l1']['ingredients'], 'ingredient'));
        $this->assertSame(['tomate' . self::USER], array_column($this->bulk->lists['l2']['rows'], 'ingredient'));
        $this->assertSame('SENT', $this->bulk->lists['l2']['status'], 'la liste envoyée garde son statut');
        $this->assertSame(['beurre' . self::USER, 'farine' . self::USER], [$this->slugOfChoice('c1'), $this->slugOfChoice('c2')]);
        $this->assertSame([1, 2], [$result->repointed->recipes, $result->repointed->shoppingLists]);
        $this->assertSame(1, $result->sentShoppingListsRepointed);
        // Les versions : +1 pour chaque recette et liste modifiée
        $this->assertSame([4, 6, 6], [$this->bulk->recipes['r1']['version'], $this->bulk->lists['l1']['version'], $this->bulk->lists['l2']['version']]);
        // La même empreinte avant et après
        $this->assertSame($before, $result->fingerprint);
        // La conversion propre est copiée avec un NOUVEL identifiant, la conversion commune jamais
        $this->assertCount(3, $this->bulk->conversions);
        $this->assertSame('beurre' . self::USER, array_values(array_filter($this->bulk->conversions, static fn(array $c): bool => $c['ingredient'] !== null && $c['ingredient'] !== 'beurre'))[0]['ingredient']);
        // L'ordre : le verrou, les alias, puis les copies et leurs conversions, puis le repointage
        $this->assertSame(['lock', 'writeAliases', 'copyIngredients', 'copyConversions', 'repointToCopies'], $this->bulk->calls);
        $this->assertSame([self::USER], array_column($this->drive->repointCalls, 'user'));
    }

    public function testASlugAlreadyTakenByAPersonalIngredientGetsASuffixAndTheExistingOneIsKept(): void
    {
        // Given : l'utilisateur a déjà son « Tomate » (le slug naturel de la copie)
        $this->personal('tomate' . self::USER, 'Tomate');
        $this->recipe('r1', self::USER, ['tomate', 'tomate' . self::USER]);
        $existing = $this->bulk->ingredients['tomate' . self::USER];

        // When
        $result = ($this->handler)(new AdoptCommonIngredientsForUserCommand(self::USER, 'migration-test'));

        // Then : la copie prend « -2 », l'ingrédient existant n'est pas modifié et garde sa ligne
        $this->assertSame(1, $result->slugCollisions);
        $this->assertSame('tomate-2' . self::USER, $this->bulk->aliases[self::USER]['tomate']['ingredient']);
        $this->assertSame($existing, $this->bulk->ingredients['tomate' . self::USER]);
        $this->assertSame(['tomate-2' . self::USER, 'tomate' . self::USER], array_column($this->bulk->recipes['r1']['rows'], 'ingredient'));
    }

    public function testASecondRunWritesNothing(): void
    {
        // Given : un premier passage complet
        $this->recipe('r1', self::USER, ['beurre', 'farine']);
        $this->list('l1', self::USER, 'ACTIVE', ['beurre']);
        ($this->handler)(new AdoptCommonIngredientsForUserCommand(self::USER, 'migration-test'));
        $afterFirst = [$this->bulk->ingredients, $this->bulk->conversions, $this->bulk->aliases, $this->bulk->recipes, $this->bulk->lists];
        $this->bulk->calls = [];

        // When
        $result = ($this->handler)(new AdoptCommonIngredientsForUserCommand(self::USER, 'migration-bis'));

        // Then : rien n'est écrit (ni copie, ni alias, ni ligne, ni version), le rapport dit « rien à faire »
        $this->assertSame($afterFirst, [$this->bulk->ingredients, $this->bulk->conversions, $this->bulk->aliases, $this->bulk->recipes, $this->bulk->lists]);
        $this->assertTrue($result->isNoop());
        $this->assertSame(['lock', 'repointToCopies'], $this->bulk->calls);
    }

    public function testASentListThatWouldBeRepointedNeedsTheExplicitAcceptanceAndNothingIsWrittenWithout(): void
    {
        // Given
        $this->recipe('r1', self::USER, ['beurre']);
        $this->list('l1', self::USER, 'SENT', ['beurre']);

        // When / Then
        try {
            ($this->handler)(new AdoptCommonIngredientsForUserCommand(self::USER, 'migration-test'));
            $this->fail('IngredientAdoptionException attendue');
        } catch (IngredientAdoptionException $e) {
            $this->assertSame(409, $e->getCode());
            $this->assertStringContainsString('--accept-sent', $e->getMessage());
        }
        $this->assertSame([], $this->bulk->aliases);
        $this->assertSame(['lock'], $this->bulk->calls);

        // When : avec l'acceptation
        $result = ($this->handler)(new AdoptCommonIngredientsForUserCommand(self::USER, 'migration-test', acceptSent: true));

        // Then
        $this->assertSame(1, $result->sentShoppingListsRepointed);
    }

    public function testASentListWithoutAnyLineOnACommonDoesNotNeedTheAcceptance(): void
    {
        // Given : la liste envoyée ne porte qu'un ingrédient personnel
        $this->personal('truc' . self::USER, 'Truc');
        $this->list('l1', self::USER, 'SENT', ['truc' . self::USER]);

        // When
        $result = ($this->handler)(new AdoptCommonIngredientsForUserCommand(self::USER, 'migration-test'));

        // Then : copies créées, liste envoyée intacte (même version)
        $this->assertSame(3, $result->adopted);
        $this->assertSame(5, $this->bulk->lists['l1']['version']);
    }

    public function testAnOrphanAliasIsReplacedInPlaceAndANonConformingExistingCopyIsNotReused(): void
    {
        // Given : un alias dont la copie a disparu, et une copie existante MODIFIÉE (nom différent) sans alias
        $this->bulk->aliases[self::USER]['beurre'] = ['ingredient' => 'beurre' . self::USER, 'kind' => 'adoption', 'source' => 'ancien'];
        $this->personal('farine' . self::USER, 'Farine de blé');
        $this->bulk->ingredients['farine' . self::USER]['adoptedFrom'] = 'farine';
        $this->recipe('r1', self::USER, ['beurre', 'farine']);

        // When
        $result = ($this->handler)(new AdoptCommonIngredientsForUserCommand(self::USER, 'migration-test'));

        // Then : l'alias orphelin désigne maintenant une vraie copie ; la copie modifiée reste, une nouvelle copie prend « -2 »
        $this->assertSame(3, $result->adopted);
        $this->assertSame('beurre' . self::USER, $this->bulk->aliases[self::USER]['beurre']['ingredient']);
        $this->assertSame('migration-test', $this->bulk->aliases[self::USER]['beurre']['source']);
        $this->assertSame('farine-2' . self::USER, $this->bulk->aliases[self::USER]['farine']['ingredient']);
        $this->assertSame('Farine de blé', $this->bulk->ingredients['farine' . self::USER]['name']);
    }

    public function testAnExistingCopyThatIsStrictlyConformingIsDesignatedInsteadOfBeingDoubled(): void
    {
        // Given : une copie identique au commun, sans alias (retour arrière d'un lancement)
        $this->bulk->ingredients['beurre' . self::USER] = ['name' => 'Beurre', 'department' => 'rayon', 'creator' => self::USER, 'adoptedFrom' => 'beurre'];
        $this->recipe('r1', self::USER, ['beurre']);

        // When
        $result = ($this->handler)(new AdoptCommonIngredientsForUserCommand(self::USER, 'migration-test'));

        // Then : elle est désignée, deux copies seulement sont créées (farine, tomate), la conversion du beurre n'est pas recopiée
        $this->assertSame([2, 1, 0], [$result->adopted, $result->reused, $result->conversionsCopied]);
        $this->assertSame('beurre' . self::USER, $this->bulk->aliases[self::USER]['beurre']['ingredient']);
        $this->assertSame(['beurre' . self::USER], array_column($this->bulk->recipes['r1']['rows'], 'ingredient'));
        $this->assertCount(2, $this->bulk->conversions);
    }

    public function testAFingerprintThatDivergesMakesTheHandlerFailWithAClearMessage(): void
    {
        // Given : un repointage qui corrompt une quantité
        $this->recipe('r1', self::USER, ['beurre']);
        $this->bulk->corruptQuantities = true;

        // When / Then
        try {
            ($this->handler)(new AdoptCommonIngredientsForUserCommand(self::USER, 'migration-test'));
            $this->fail('IngredientAdoptionException attendue');
        } catch (IngredientAdoptionException $e) {
            $this->assertStringContainsString('rien n\'est écrit pour ce compte', $e->getMessage());
            $this->assertStringContainsString('recipe_row : la somme des quantités a changé', $e->getMessage());
            $this->assertStringContainsString('le contenu de 1 a changé', $e->getMessage());
        }
    }

    public function testADriveChoiceLeftOnTheOldSlugMakesTheHandlerFail(): void
    {
        // Given : le port du drive ne repointe rien
        $this->recipe('r1', self::USER, ['beurre']);
        $this->drive->choices['c1'] = ['user' => self::USER, 'slug' => 'beurre', 'products' => 2];
        $this->drive->ignoreRepoint = true;

        // When / Then
        try {
            ($this->handler)(new AdoptCommonIngredientsForUserCommand(self::USER, 'migration-test'));
            $this->fail('IngredientAdoptionException attendue');
        } catch (IngredientAdoptionException $e) {
            $this->assertStringContainsString("choix du drive : 1 ne portent pas l'ingrédient attendu", $e->getMessage());
        }
    }

    public function testASlugTooLongIsRefusedBeforeAnyWrite(): void
    {
        // Given : un commun dont le nom ferait un slug de plus de 255 caractères
        $this->bulk->ingredients['long'] = ['name' => str_repeat('a', 252), 'department' => 'rayon', 'creator' => null, 'adoptedFrom' => null];
        $this->recipe('r1', self::USER, ['beurre']);

        // When / Then
        $this->expectException(IngredientAdoptionException::class);
        $this->expectExceptionMessage('maximum est 255');
        try {
            ($this->handler)(new AdoptCommonIngredientsForUserCommand(self::USER, 'migration-test'));
        } finally {
            $this->assertSame([], $this->bulk->aliases);
        }
    }

    // --- Aides

    private function handlerWith(IngredientAdoptionBulkInMemoryRepository $bulk, DriveIngredientReferencesInMemoryRepository $drive): AdoptCommonIngredientsForUserHandler
    {
        $uuids = new class implements UuidGenerator {
            private int $next = 0;

            public function generate(): string
            {
                return 'new-conversion-' . ++$this->next;
            }
        };

        return new AdoptCommonIngredientsForUserHandler($bulk, $drive, new IngredientSlugPolicy(), $uuids, new FrozenClock(new DateTimeImmutable(self::NOW)), new AdoptionVerifier());
    }

    /**
     * Le slug d'un choix du drive, relu dans l'état du double (l'analyse statique ne le croit pas figé par la préparation du test).
     */
    private function slugOfChoice(string $id): string
    {
        return $this->drive->choices[$id]['slug'];
    }

    private function personal(string $slug, string $name): void
    {
        $this->bulk->ingredients[$slug] = ['name' => $name, 'department' => 'rayon', 'creator' => self::USER, 'adoptedFrom' => null];
    }

    /**
     * @param list<string> $ingredients
     */
    private function recipe(string $id, string $author, array $ingredients): void
    {
        $this->bulk->recipes[$id] = ['author' => $author, 'version' => 3, 'rows' => array_map(static fn(string $slug): array => ['ingredient' => $slug, 'quantity' => 1.5, 'unit' => 'gramme'], $ingredients)];
    }

    /**
     * @param list<string> $rows
     * @param list<string> $ingredients
     */
    private function list(string $id, string $owner, string $status, array $rows, array $ingredients = []): void
    {
        $line = static fn(string $slug): array => ['ingredient' => $slug, 'quantity' => 2.0, 'unit' => 'gramme'];
        $this->bulk->lists[$id] = [
            'owner' => $owner,
            'version' => 5,
            'status' => $status,
            'sentAt' => $status === 'SENT' ? '2026-10-10 12:00:00' : null,
            'rows' => array_map($line, $rows),
            'ingredients' => array_map($line, $ingredients),
        ];
    }
}
