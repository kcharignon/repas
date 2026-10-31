<?php

namespace Repas\Tests\Repas\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\SeedUserIngredients\SeedOutcome;
use Repas\Repas\Application\Command\SeedUserIngredients\SeedUserIngredientsCommand;
use Repas\Repas\Application\Command\SeedUserIngredients\SeedUserIngredientsHandler;
use Repas\Repas\Application\Service\AdoptionVerifier;
use Repas\Repas\Domain\Exception\IngredientAdoptionException;
use Repas\Repas\Domain\Service\IngredientSlugPolicy;
use Repas\Shared\Domain\Interface\UuidGenerator;
use Repas\Tests\Helper\FrozenClock;
use Repas\Repas\Infrastructure\Acl\CatalogOwnerCommandAdapter;
use Repas\Repas\Infrastructure\Acl\SeedingAccountCommandAdapter;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\UserInMemoryRepository;
use Repas\User\Application\OpenHostService\CatalogOwnerCommandOHS;
use Repas\User\Application\OpenHostService\UserAccountCommandOHS;
use Repas\Tests\Helper\InMemoryRepository\DriveIngredientReferencesInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientAdoptionBulkInMemoryRepository;
use Repas\Tests\Helper\SpyLogger;
use Repas\User\Domain\Model\User;

/**
 * L'orchestration de l'amorçage d'UN compte (ING-008, `docs/ingredients/sans-commun.md` §§ 2 et 6), sans base : périmètre, source (de base,
 * catalogue du propriétaire ou sans créateur), marqueur d'un autre compte inerte, rattrapage d'un compte qui a des données, idempotence,
 * empreintes qui divergent, aucun événement. Le SQL est prouvé sur la vraie base (`IngredientSeedingRepositoryTest`) ; la transaction,
 * par les tests de l'activation.
 */
class SeedUserIngredientsHandlerTest extends TestCase
{
    private const string OWNER = 'owner-1';
    private const string USER = 'user-1';
    private const string NOW = '2026-10-21 03:00:00';

    private IngredientAdoptionBulkInMemoryRepository $bulk;
    private DriveIngredientReferencesInMemoryRepository $drive;
    private UserInMemoryRepository $accounts;
    private SpyLogger $logger;
    private SeedUserIngredientsHandler $handler;

    protected function setUp(): void
    {
        $this->bulk = new IngredientAdoptionBulkInMemoryRepository();
        $this->drive = new DriveIngredientReferencesInMemoryRepository();
        $this->accounts = new UserInMemoryRepository([
            new UserBuilder()->withId(self::OWNER)->isCatalogOwner()->build(),
            new UserBuilder()->withId(self::USER)->withEmail('user-1@example.com')->build(),
            new UserBuilder()->withId('other-user')->withEmail('other-user@example.com')->build(),
        ]);
        $this->logger = new SpyLogger();
        $this->handler = $this->handlerWith($this->accounts);

        // Le catalogue du propriétaire : cinq ingrédients dont trois « de base » (le beurre a deux conversions propres)
        $this->catalog('beurre', 'Beurre', base: true);
        $this->catalog('farine', 'Farine', base: true);
        $this->catalog('tomate', 'Tomate', base: true);
        $this->catalog('mayonnaise-maison', 'Mayonnaise maison', base: false);
        $this->catalog('piment-special', 'Piment spécial', base: false);
        $this->bulk->conversions = [
            ['id' => 'conversion-beurre-1', 'ingredient' => 'beurre'],
            ['id' => 'conversion-beurre-2', 'ingredient' => 'beurre'],
            ['id' => 'conversion-mayonnaise', 'ingredient' => 'mayonnaise-maison'],
            ['id' => 'conversion-generale', 'ingredient' => null],
        ];
    }

    public function testTheSeedingHandlerEmitsNoEventAndNeverSavesTheAccount(): void
    {
        // Un amorçage n'est pas une création (ADR 0028 décision 7) et n'enregistre jamais le compte (ADR 0013 règle 7) : aucun répartiteur
        // d'événements, aucun bus, aucun dépôt d'utilisateur dans ses dépendances
        $types = array_map(
            static fn(\ReflectionParameter $parameter): string => (string) $parameter->getType(),
            new \ReflectionClass(SeedUserIngredientsHandler::class)->getConstructor()->getParameters(),
        );
        $this->assertSame([], array_values(array_filter($types, static fn(string $type): bool => str_contains($type, 'EventDispatcher') || str_contains($type, 'MessageBus') || str_contains($type, 'CommandBus') || str_contains($type, 'UserRepository'))));
    }

    public function testAnAccountReceivesTheBaseIngredientsOnlyWithTheirOwnConversions(): void
    {
        // When
        $result = $this->seed();

        // Then : les trois de base, avec le créateur, l'origine, le marqueur à faux
        $this->assertSame(SeedOutcome::SEEDED, $result->outcome);
        $this->assertSame([3, 0, 0, 2], [$result->seeded, $result->seededBecauseReferenced, $result->reused, $result->conversionsCopied]);
        $this->assertSame(['beurre', 'farine', 'tomate'], array_keys($this->bulk->aliases[self::USER]));
        $this->assertSame(['name' => 'Beurre', 'department' => 'rayon', 'creator' => self::USER, 'adoptedFrom' => 'beurre', 'isBase' => false], $this->bulk->ingredients['beurre' . self::USER]);
        $this->assertSame(self::USER, $this->bulk->ingredients['tomate' . self::USER]['creator']);
        // Les deux qui ne sont pas « de base » ne sont pas copiés
        $this->assertArrayNotHasKey('mayonnaise-maison' . self::USER, $this->bulk->ingredients);
        $this->assertArrayNotHasKey('piment-special' . self::USER, $this->bulk->ingredients);
        // Les alias portent la source de l'activation
        $this->assertSame(['activation'], array_values(array_unique(array_column($this->bulk->aliases[self::USER], 'source'))));
        $this->assertSame('beurre' . self::USER, $this->bulk->aliases[self::USER]['beurre']['ingredient']);
        // Les conversions propres du beurre sont recopiées avec de nouveaux identifiants, ni la générale ni celle d'un ingrédient non copié
        $copied = array_values(array_filter($this->bulk->conversions, static fn(array $c): bool => $c['ingredient'] === 'beurre' . self::USER));
        $this->assertCount(2, $copied);
        $this->assertSame(['new-conversion-1', 'new-conversion-2'], array_column($copied, 'id'));
        $this->assertCount(6, $this->bulk->conversions);
        // Rien n'est repointé chez un compte neuf
        $this->assertTrue($result->repointed->isEmpty());
        // L'ordre : les verrous, les alias, puis les copies et leurs conversions, puis le repointage
        $this->assertSame(['lockSeeding', 'writeAliases', 'copyCatalogIngredients', 'copyConversions', 'repointToCopies'], $this->bulk->calls);
    }

    public function testTheCopiesNeverCarryTheBaseMarkerEvenWhenTheirSourceDoes(): void
    {
        // When
        $this->seed();

        // Then
        foreach (['beurre', 'farine', 'tomate'] as $slug) {
            $this->assertTrue($this->bulk->ingredients[$slug]['isBase']);
            $this->assertFalse($this->bulk->ingredients[$slug . self::USER]['isBase'], $slug);
        }
    }

    public function testTheCatalogOwnerIsNeverSeededAndNothingIsWritten(): void
    {
        // Given : le propriétaire lui-même (ses ingrédients sont les siens)
        $before = [$this->bulk->ingredients, $this->bulk->aliases, $this->bulk->conversions];

        // When
        $result = ($this->handler)(new SeedUserIngredientsCommand(self::OWNER, 'rattrapage-test', complete: true));

        // Then
        $this->assertSame(SeedOutcome::NOT_CONCERNED, $result->outcome);
        $this->assertSame($before, [$this->bulk->ingredients, $this->bulk->aliases, $this->bulk->conversions]);
        $this->assertSame(['lockSeeding'], $this->bulk->calls);
    }

    public function testAnotherAdministratorIsAnOrdinaryAccountAndIsSeeded(): void
    {
        // Given : le propriétaire est désigné par le port, pas par le rôle : un second administrateur est un compte ordinaire
        $this->bulk->administrators[] = 'other-user';

        // When
        $result = ($this->handler)(new SeedUserIngredientsCommand('other-user', 'activation'));

        // Then
        $this->assertSame(SeedOutcome::SEEDED, $result->outcome);
        $this->assertSame(3, $result->seeded);
    }

    public function testAnUnknownAccountIsNotConcerned(): void
    {
        // When
        $result = ($this->handler)(new SeedUserIngredientsCommand('ghost', 'activation'));

        // Then
        $this->assertSame(SeedOutcome::NOT_CONCERNED, $result->outcome);
        $this->assertSame([], $this->bulk->aliases);
    }

    public function testASecondActivationOfAnAlreadySeededAccountWritesNothingNotEvenVersions(): void
    {
        // Given : un compte amorcé, avec une recette sur ses copies
        $this->seed();
        $this->recipe('r1', self::USER, ['beurre' . self::USER]);
        $state = [$this->bulk->ingredients, $this->bulk->conversions, $this->bulk->aliases, $this->bulk->recipes, $this->bulk->lists];
        $this->bulk->calls = [];

        // When : il est désactivé puis réactivé
        $result = ($this->handler)(new SeedUserIngredientsCommand(self::USER, 'activation'));

        // Then : rien n'est écrit, y compris les versions, et la requête de la source n'est même pas posée
        $this->assertSame(SeedOutcome::ALREADY_SEEDED, $result->outcome);
        $this->assertSame($state, [$this->bulk->ingredients, $this->bulk->conversions, $this->bulk->aliases, $this->bulk->recipes, $this->bulk->lists]);
        $this->assertSame(['lockSeeding'], $this->bulk->calls);
    }

    public function testAnAccountThatDeletedAllItsCopiesIsServedAgain(): void
    {
        // Given : un compte amorcé qui a ensuite supprimé toutes ses copies (la suppression d'un ingrédient efface son alias)
        $this->seed();
        foreach (['beurre', 'farine', 'tomate'] as $slug) {
            unset($this->bulk->ingredients[$slug . self::USER], $this->bulk->aliases[self::USER][$slug]);
        }
        $this->bulk->conversions = array_values(array_filter($this->bulk->conversions, static fn(array $c): bool => $c['ingredient'] !== 'beurre' . self::USER));

        // When
        $result = ($this->handler)(new SeedUserIngredientsCommand(self::USER, 'activation'));

        // Then : il est de nouveau non amorcé, donc servi
        $this->assertSame([SeedOutcome::SEEDED, 3], [$result->outcome, $result->seeded]);
    }

    public function testAnOrphanAliasStillMakesTheAccountSeededBecauseTheDefinitionIsTheOneOfTheAliasPort(): void
    {
        // Given : un alias d'adoption sans copie (laissé par un retour arrière du code) : « amorcé » = au moins un alias d'adoption
        $this->bulk->aliases[self::USER]['beurre'] = ['ingredient' => 'beurre' . self::USER, 'kind' => 'adoption', 'source' => 'ancien'];

        // When
        $result = ($this->handler)(new SeedUserIngredientsCommand(self::USER, 'activation'));

        // Then
        $this->assertSame(SeedOutcome::ALREADY_SEEDED, $result->outcome);
    }

    public function testAMergeAliasAloneDoesNotMakeTheAccountSeeded(): void
    {
        // Given : un alias de fusion (ni adoption) : le compte n'a rien reçu
        $this->bulk->aliases[self::USER]['ancien-slug'] = ['ingredient' => 'autre' . self::USER, 'kind' => 'merge', 'source' => 'merge'];

        // When
        $result = ($this->handler)(new SeedUserIngredientsCommand(self::USER, 'activation'));

        // Then
        $this->assertSame(SeedOutcome::SEEDED, $result->outcome);
    }

    public function testTheTransitionalRuleGivesTheSameIngredientsBeforeAndAfterTheHandover(): void
    {
        // Given : avant la passation, les anciens communs n'ont pas de créateur ; le propriétaire n'a rien
        foreach (['beurre', 'farine', 'tomate', 'mayonnaise-maison', 'piment-special'] as $slug) {
            $this->bulk->ingredients[$slug]['creator'] = null;
        }

        // When
        $before = $this->seed();

        // Given : après la passation, les mêmes slugs sont au propriétaire ; un autre compte est amorcé
        foreach (['beurre', 'farine', 'tomate', 'mayonnaise-maison', 'piment-special'] as $slug) {
            $this->bulk->ingredients[$slug]['creator'] = self::OWNER;
        }
        $after = ($this->handler)(new SeedUserIngredientsCommand('other-user', 'activation'));

        // Then : exactement les mêmes ingrédients
        $this->assertSame([SeedOutcome::SEEDED, 3], [$before->outcome, $before->seeded]);
        $this->assertSame([SeedOutcome::SEEDED, 3], [$after->outcome, $after->seeded]);
        $this->assertSame(['beurre', 'farine', 'tomate'], array_keys($this->bulk->aliases[self::USER]));
        $this->assertSame(['beurre', 'farine', 'tomate'], array_keys($this->bulk->aliases['other-user']));
        $this->assertSame(self::USER, $this->bulk->ingredients['farine' . self::USER]['creator']);
        $this->assertSame('farine', $this->bulk->ingredients['farine' . 'other-user']['adoptedFrom']);
    }

    public function testWhenNothingIsMarkedBaseNothingIsWrittenAndTheAccountStaysUnseeded(): void
    {
        // Given : le classement n'est pas posé (marqueur partout à faux)
        foreach ($this->bulk->ingredients as $slug => $_) {
            $this->bulk->ingredients[$slug]['isBase'] = false;
        }

        // When
        $result = $this->seed();

        // Then : rien n'écrit, le compte n'est pas marqué amorcé (il sera servi par le rattrapage), et un avertissement est journalisé
        $this->assertSame(SeedOutcome::NO_CATALOG, $result->outcome);
        $this->assertSame([], $this->bulk->aliases);
        $this->assertSame(['lockSeeding'], $this->bulk->calls);
        $this->assertCount(1, $this->logger->messages('warning'));
        $this->assertStringNotContainsString(self::USER, $this->logger->messages('warning')[0]);
    }

    public function testWhenNothingIsMarkedBaseAnAccountWithDataIsStillNotSeededWithOnlyWhatItsLinesReference(): void
    {
        // Given : le classement n'est pas posé, et le compte a une recette sur un ingrédient du catalogue
        foreach ($this->bulk->ingredients as $slug => $_) {
            $this->bulk->ingredients[$slug]['isBase'] = false;
        }
        $this->recipe('r1', self::USER, ['mayonnaise-maison']);

        // When
        $result = $this->seed();

        // Then : sinon il serait « amorcé » avec une partie du catalogue et ne serait jamais servi pour le reste
        $this->assertSame(SeedOutcome::NO_CATALOG, $result->outcome);
        $this->assertSame([], $this->bulk->aliases);
        $this->assertSame(['mayonnaise-maison'], array_column($this->bulk->recipes['r1']['rows'], 'ingredient'));
    }

    public function testWithoutACatalogOwnerNothingIsWritten(): void
    {
        // Given
        $handler = $this->handlerWith(new UserInMemoryRepository([new UserBuilder()->withId(self::USER)->build()]));

        // When
        $result = $handler(new SeedUserIngredientsCommand(self::USER, 'activation'));

        // Then
        $this->assertSame(SeedOutcome::NO_CATALOG, $result->outcome);
        $this->assertSame([], $this->bulk->aliases);
        $this->assertCount(1, $this->logger->messages('warning'));
    }

    public function testTheBaseMarkerOfAnotherAccountIsInert(): void
    {
        // Given : l'ingrédient d'un utilisateur ordinaire porte le marqueur (accès direct à la base) ; un autre est « de base » chez un tiers
        $this->bulk->ingredients['gadget' . 'other-user'] = ['name' => 'Gadget', 'department' => 'rayon', 'creator' => 'other-user', 'adoptedFrom' => null, 'isBase' => true];

        // When
        $result = $this->seed();

        // Then : jamais copié
        $this->assertSame(3, $result->seeded);
        $this->assertSame(['beurre', 'farine', 'tomate'], array_keys($this->bulk->aliases[self::USER]));
        $this->assertArrayNotHasKey('gadget' . self::USER, $this->bulk->ingredients);
    }

    public function testAnAccountWithDataAlsoReceivesTheCatalogIngredientsItsLinesReferenceAndTheyAreRepointed(): void
    {
        // Given : le compte vide d'avant la passation a une recette, une liste, une liste envoyée et un choix du drive sur des ingrédients du
        // catalogue, dont deux qui ne sont PAS « de base »
        $this->recipe('r1', self::USER, ['beurre', 'mayonnaise-maison']);
        $this->list('l1', self::USER, 'ACTIVE', ['farine'], ['piment-special']);
        $this->list('l2', self::USER, 'SENT', ['mayonnaise-maison']);
        $this->drive->choices['c1'] = ['user' => self::USER, 'slug' => 'piment-special', 'products' => 3];
        $this->drive->choices['c2'] = ['user' => self::USER, 'slug' => 'beurre', 'products' => 1];
        $before = $this->bulk->snapshot(self::USER, [])->contentFingerprint();

        // When
        $result = $this->seed();

        // Then : les trois de base plus les deux référencés
        $this->assertSame([5, 2, 0, 3], [$result->seeded, $result->seededBecauseReferenced, $result->reused, $result->conversionsCopied]);
        $this->assertSame(['beurre', 'farine', 'mayonnaise-maison', 'piment-special', 'tomate'], array_keys($this->bulk->aliases[self::USER]));
        // Les lignes sont repointées sur les copies
        $this->assertSame(['beurre' . self::USER, 'mayonnaise-maison' . self::USER], array_column($this->bulk->recipes['r1']['rows'], 'ingredient'));
        $this->assertSame(['farine' . self::USER], array_column($this->bulk->lists['l1']['rows'], 'ingredient'));
        $this->assertSame(['piment-special' . self::USER], array_column($this->bulk->lists['l1']['ingredients'], 'ingredient'));
        $this->assertSame(['mayonnaise-maison' . self::USER], array_column($this->bulk->lists['l2']['rows'], 'ingredient'));
        $this->assertSame(['piment-special' . self::USER, 'beurre' . self::USER], [$this->slugOfChoice('c1'), $this->slugOfChoice('c2')]);
        // La liste envoyée garde son statut, ses versions montent de 1 et rien d'autre
        $this->assertSame('SENT', $this->bulk->lists['l2']['status']);
        $this->assertSame([4, 6, 6], [$this->bulk->recipes['r1']['version'], $this->bulk->lists['l1']['version'], $this->bulk->lists['l2']['version']]);
        $this->assertSame(1, $result->sentShoppingListsRepointed);
        $this->assertSame(2, $result->driveChoicesRepointed);
        // Le contenu vu par les noms est identique avant et après
        $this->assertSame($before, $this->bulk->snapshot(self::USER, [])->contentFingerprint());
        $this->assertSame($before, $result->fingerprint);
    }

    public function testASentListThatWouldBeRepointedNeedsTheExplicitAcceptanceAndNothingIsWrittenWithout(): void
    {
        // Given
        $this->list('l1', self::USER, 'SENT', ['beurre']);

        // When / Then
        try {
            ($this->handler)(new SeedUserIngredientsCommand(self::USER, 'rattrapage-test', acceptSent: false));
            $this->fail('IngredientAdoptionException attendue');
        } catch (IngredientAdoptionException $e) {
            $this->assertSame(409, $e->getCode());
            $this->assertStringContainsString('--accept-sent', $e->getMessage());
        }
        $this->assertSame([], $this->bulk->aliases);
        $this->assertSame(['lockSeeding'], $this->bulk->calls);
    }

    public function testASlugAlreadyTakenByAPersonalIngredientGetsASuffix(): void
    {
        // Given : le compte a déjà son « Tomate » personnel (le slug naturel de la copie)
        $this->bulk->ingredients['tomate' . self::USER] = ['name' => 'Tomate', 'department' => 'rayon', 'creator' => self::USER, 'adoptedFrom' => null];

        // When
        $result = $this->seed();

        // Then : la copie prend « -2 », l'ingrédient existant n'est pas modifié
        $this->assertSame(1, $result->slugCollisions);
        $this->assertSame('tomate-2' . self::USER, $this->bulk->aliases[self::USER]['tomate']['ingredient']);
        $this->assertSame('tomate', $this->bulk->ingredients['tomate-2' . self::USER]['adoptedFrom']);
    }

    public function testAnExistingConformingCopyIsDesignatedInsteadOfBeingDoubled(): void
    {
        // Given : une copie de recette qui a retenu l'origine (adopted_from), conforme, sans alias
        $this->bulk->ingredients['beurre' . self::USER] = ['name' => 'Beurre', 'department' => 'rayon', 'creator' => self::USER, 'adoptedFrom' => 'beurre'];

        // When
        $result = $this->seed();

        // Then : désignée par l'alias, deux copies seulement créées, la conversion du beurre n'est pas recopiée
        $this->assertSame([2, 1, 0], [$result->seeded, $result->reused, $result->conversionsCopied]);
        $this->assertSame('beurre' . self::USER, $this->bulk->aliases[self::USER]['beurre']['ingredient']);
        $this->assertCount(4, $this->bulk->conversions);
    }

    public function testCompleteAddsOnlyWhatAnAlreadySeededAccountIsMissingThenHasNothingToAdd(): void
    {
        // Given : un compte amorcé, puis le propriétaire marque un nouvel ingrédient « de base »
        $this->seed();
        $this->catalog('basilic', 'Basilic', base: true);
        $this->bulk->calls = [];

        // When : sans l'option, rien ; avec, le seul manquant
        $without = ($this->handler)(new SeedUserIngredientsCommand(self::USER, 'rattrapage-test'));
        $with = ($this->handler)(new SeedUserIngredientsCommand(self::USER, 'rattrapage-test', complete: true));
        $again = ($this->handler)(new SeedUserIngredientsCommand(self::USER, 'rattrapage-test', complete: true));

        // Then
        $this->assertSame(SeedOutcome::ALREADY_SEEDED, $without->outcome);
        $this->assertSame([SeedOutcome::SEEDED, 1], [$with->outcome, $with->seeded]);
        $this->assertSame('rattrapage-test', $this->bulk->aliases[self::USER]['basilic']['source']);
        $this->assertSame('activation', $this->bulk->aliases[self::USER]['beurre']['source']);
        $this->assertSame(SeedOutcome::NOTHING_TO_ADD, $again->outcome);
    }

    public function testCompleteAlsoRepointsALineAndAChoiceLeftOnTheOriginOfAnAdoptionTheAccountAlreadyHas(): void
    {
        // Given : un compte amorcé, puis un vieil onglet remet une ligne de recette, une ligne de liste et un choix du drive sur l'origine
        $this->seed();
        $this->recipe('r1', self::USER, ['beurre']);
        $this->list('l1', self::USER, 'ACTIVE', ['farine']);
        $this->drive->choices['c1'] = ['user' => self::USER, 'slug' => 'tomate', 'products' => 2];
        $this->bulk->calls = [];

        // When : sans l'option rien n'est touché ; avec, la ligne et le choix sont repointés sur les copies déjà reçues, sans nouvelle copie
        $without = ($this->handler)(new SeedUserIngredientsCommand(self::USER, 'rattrapage-test'));
        $with = ($this->handler)(new SeedUserIngredientsCommand(self::USER, 'rattrapage-test', complete: true));
        $again = ($this->handler)(new SeedUserIngredientsCommand(self::USER, 'rattrapage-test', complete: true));

        // Then
        $this->assertSame(SeedOutcome::ALREADY_SEEDED, $without->outcome);
        $this->assertSame([SeedOutcome::SEEDED, 0, 1, 1], [$with->outcome, $with->seeded, $with->repointed->recipeRows, $with->repointed->shoppingListRows]);
        $this->assertSame(1, $with->driveChoicesRepointed);
        $this->assertSame(['beurre' . self::USER], array_column($this->bulk->recipes['r1']['rows'], 'ingredient'));
        $this->assertSame(['farine' . self::USER], array_column($this->bulk->lists['l1']['rows'], 'ingredient'));
        $this->assertSame('tomate' . self::USER, $this->slugOfChoice('c1'));
        $this->assertSame([4, 6], [$this->bulk->recipes['r1']['version'], $this->bulk->lists['l1']['version']]);
        $this->assertNotContains('writeAliases', $this->bulk->calls, 'aucun alias de plus : rien n\'est copié');
        $this->assertNotContains('copyCatalogIngredients', $this->bulk->calls);
        $this->assertSame(SeedOutcome::NOTHING_TO_ADD, $again->outcome);
    }

    public function testCompleteOnAnAccountThatNeedsNothingWritesNothing(): void
    {
        // Given : un compte amorcé dont les lignes sont sur ses copies
        $this->seed();
        $this->recipe('r1', self::USER, ['beurre' . self::USER]);
        $state = [$this->bulk->ingredients, $this->bulk->aliases, $this->bulk->recipes];

        // When
        $result = ($this->handler)(new SeedUserIngredientsCommand(self::USER, 'rattrapage-test', complete: true));

        // Then
        $this->assertSame(SeedOutcome::NOTHING_TO_ADD, $result->outcome);
        $this->assertSame($state, [$this->bulk->ingredients, $this->bulk->aliases, $this->bulk->recipes]);
    }

    public function testAFingerprintThatDivergesMakesTheHandlerFailWithAClearMessage(): void
    {
        // Given : un repointage qui corrompt une quantité
        $this->recipe('r1', self::USER, ['beurre']);
        $this->bulk->corruptQuantities = true;

        // When / Then
        try {
            $this->seed();
            $this->fail('IngredientAdoptionException attendue');
        } catch (IngredientAdoptionException $e) {
            $this->assertStringContainsString('rien n\'est écrit pour ce compte', $e->getMessage());
            $this->assertStringContainsString('recipe_row : la somme des quantités a changé', $e->getMessage());
        }
    }

    public function testStatisticsOfTheAccountThatMoveMakeTheHandlerFail(): void
    {
        // Given : un amorçage qui, par une écriture venue d'ailleurs, ferait bouger les statistiques du compte entre l'avant et l'après
        $accounts = new class([new UserBuilder()->withId(self::OWNER)->isCatalogOwner()->build(), new UserBuilder()->withId(self::USER)->withEmail('user-1@example.com')->build()]) extends UserInMemoryRepository {
            private int $reads = 0;

            public function findOneById(string $id): User
            {
                $user = parent::findOneById($id);
                // 1 : le compte existe ; 2 : l'empreinte d'avant ; 3 : l'empreinte d'après
                if (++$this->reads === 3) {
                    $user->createRecipe();
                }

                return $user;
            }
        };
        $handler = $this->handlerWith($accounts);

        // When / Then
        try {
            $handler(new SeedUserIngredientsCommand(self::USER, 'activation'));
            $this->fail('IngredientAdoptionException attendue');
        } catch (IngredientAdoptionException $e) {
            $this->assertStringContainsString('les statistiques du compte ont changé', $e->getMessage());
        }
    }

    public function testADriveChoiceLeftOnTheOldSlugMakesTheHandlerFail(): void
    {
        // Given : le port du drive ne repointe rien
        $this->drive->choices['c1'] = ['user' => self::USER, 'slug' => 'beurre', 'products' => 2];
        $this->drive->ignoreRepoint = true;

        // When / Then
        try {
            $this->seed();
            $this->fail('IngredientAdoptionException attendue');
        } catch (IngredientAdoptionException $e) {
            $this->assertStringContainsString("choix du drive : 1 ne portent pas l'ingrédient attendu", $e->getMessage());
        }
    }

    public function testASlugTooLongIsRefusedBeforeAnyWriteAndTheMessageNamesNoIngredient(): void
    {
        // Given : un ingrédient de base dont le nom ferait un slug de plus de 255 caractères
        $this->catalog('long', str_repeat('a', 252), base: true);

        // When / Then
        try {
            $this->seed();
            $this->fail('IngredientAdoptionException attendue');
        } catch (IngredientAdoptionException $e) {
            $this->assertStringContainsString('maximum est 255', $e->getMessage());
            $this->assertStringNotContainsString('aaaa', $e->getMessage());
        }
        $this->assertSame([], $this->bulk->aliases);
    }

    public function testTheCatalogOwnerIsReadOnceAndTheSourceIsReadOnlyWhenTheAccountIsInScope(): void
    {
        // When
        ($this->handler)(new SeedUserIngredientsCommand('ghost', 'activation'));
        $callsForAGhost = count($this->accounts->findOneByEmailCalls);
        $this->seed();

        // Then
        $this->assertSame(1, $callsForAGhost);
        $this->assertSame(2, count($this->accounts->findOneByEmailCalls));
    }

    // --- Aides

    private function seed(): \Repas\Repas\Application\Command\SeedUserIngredients\SeedResult
    {
        return ($this->handler)(new SeedUserIngredientsCommand(self::USER, 'activation'));
    }

    private function handlerWith(UserInMemoryRepository $accounts): SeedUserIngredientsHandler
    {
        $uuids = new class implements UuidGenerator {
            private int $next = 0;

            public function generate(): string
            {
                return 'new-conversion-' . ++$this->next;
            }
        };

        return new SeedUserIngredientsHandler($this->bulk, new CatalogOwnerCommandAdapter(new CatalogOwnerCommandOHS($accounts, UserBuilder::CATALOG_OWNER_EMAIL)), new SeedingAccountCommandAdapter(new UserAccountCommandOHS($accounts)), $this->drive, new IngredientSlugPolicy(), $uuids, new FrozenClock(new DateTimeImmutable(self::NOW)), new AdoptionVerifier(), $this->logger);
    }

    private function catalog(string $slug, string $name, bool $base): void
    {
        $this->bulk->ingredients[$slug] = ['name' => $name, 'department' => 'rayon', 'creator' => self::OWNER, 'adoptedFrom' => null, 'isBase' => $base];
    }

    private function slugOfChoice(string $id): string
    {
        return $this->drive->choices[$id]['slug'];
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
