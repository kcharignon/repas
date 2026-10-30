<?php

namespace Repas\Tests\Drive\OutputAdapter;


use DateTimeImmutable;
use Repas\Drive\Application\Loader\DriveSettingsLoader;
use Repas\Drive\Application\Query\GetDriveSettings\DriveRetailerDTO;
use Repas\Drive\Application\Query\GetDriveSettings\DriveSettingsDTO;
use Repas\Drive\Application\Query\GetDriveSettings\DriveStoreDTO;
use Repas\Drive\Application\Query\GetDriveSettings\RetailerSelectionDTO;
use Repas\Drive\Domain\Interface\DriveSettingsRepository;
use Repas\Drive\Domain\Interface\RetailerPreferenceRepository;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Domain\Model\RetailerPreference;
use Repas\Drive\Domain\Service\RetailerSelection;
use Repas\Drive\Infrastructure\Loader\DriveSettingsPostgreSQLLoader;
use Repas\Tests\Helper\Builder\DriveSettingsBuilder;
use Repas\Tests\Helper\ControlledDriveFeature;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\SqlBudget\QueryCounter;

class DriveSettingsPostgreSQLLoaderTest extends DatabaseTestCase
{
    private const string AUCHAN_STORE = '0b5f8a2e-3c1d-4e7f-9a6b-123456789abc';

    private DriveSettingsRepository $repository;
    private RetailerPreferenceRepository $preferences;
    private DriveSettingsLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = static::getContainer()->get(DriveSettingsRepository::class);
        $this->preferences = static::getContainer()->get(RetailerPreferenceRepository::class);
        // La production d'aujourd'hui : une seule enseigne allumée
        $this->loader = $this->loaderFor(['leclerc']);
    }

    public function testAUserWithoutSettingsGetsTheSwitchAndNoStore(): void
    {
        // When
        $result = $this->loader->get('user-1');

        // Then : aucune enseigne configurée, la sélection est `NONE` et l'enseigne en vigueur celle d'avant
        $this->assertEquals(
            new DriveSettingsDTO(true, 'leclerc', null, [new DriveRetailerDTO('leclerc', 'Leclerc', false, false, null)], null, null, new RetailerSelectionDTO('NONE', null, [])),
            $result,
        );
    }

    public function testAUserWithSettingsGetsHisStore(): void
    {
        // Given
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-2')->withStore('654321', null, 'https://autre.leclercdrive.fr/magasin-654321-654321')->build());

        // When
        $result = $this->loader->get('user-1', Retailer::default());

        // Then : seul son magasin, avec son libellé ; une seule enseigne configurée, elle s'impose sans rien demander
        $this->assertEquals(
            new DriveSettingsDTO(true, 'leclerc', $store = new DriveStoreDTO('123456', 'Ville-Test', 'https://exemple.leclercdrive.fr/magasin-123456-123456'), [new DriveRetailerDTO('leclerc', 'Leclerc', true, false, $store)], null, null, new RetailerSelectionDTO('AUTO', 'leclerc', ['leclerc'])),
            $result,
        );
        $this->assertNull($this->loader->get('user-3', Retailer::default())->store);
    }

    public function testWithoutARetailerAskedTheSettingsAreThoseOfTheSelectionLikeTheOldBehaviourWithASingleRetailer(): void
    {
        // Given
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->build());

        // When / Then : la même réponse qu'avec l'enseigne par défaut demandée
        $this->assertEquals($this->loader->get('user-1', Retailer::default()), $this->loader->get('user-1'));
        $this->assertEquals($this->loader->get('user-2', Retailer::default()), $this->loader->get('user-2'));
    }

    public function testOnlyTheEnabledRetailersAreListedAndTheOtherFieldsDoNotChange(): void
    {
        // Given : un magasin enregistré, aucune enseigne allumée
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $loader = $this->loaderFor([]);

        // When
        $result = $loader->get('user-1', Retailer::default());

        // Then : aucune entrée dans `retailers` (DRV-702), le reste est ce qu'il était, et il n'y a rien à choisir
        $this->assertSame([], $result->retailers);
        $this->assertTrue($result->enabled);
        $this->assertSame('leclerc', $result->retailer);
        $this->assertSame('123456', $result->store?->id);
        $this->assertEquals(new RetailerSelectionDTO('NONE', null, []), $result->selection);
        $this->assertEquals($result, $loader->get('user-1'));

        // When : on rallume l'enseigne
        $again = $this->loaderFor([Retailer::default()->value])->get('user-1', Retailer::default());

        // Then : ses données étaient restées
        $this->assertCount(1, $again->retailers);
        $this->assertTrue($again->retailers[0]->configured);
        $this->assertSame('AUTO', $again->selection->mode);
    }

    public function testAStoreWithoutLabelHasANullLabel(): void
    {
        // Given
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->withStore('654321', null, 'https://autre.leclercdrive.fr/magasin-654321-654321')->build());

        // Then
        $this->assertNull($this->loader->get('user-1', Retailer::default())->store->label);
    }

    public function testTheSwitchComesFromTheDriveFeature(): void
    {
        // Given
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $loader = new DriveSettingsPostgreSQLLoader($this->entityManager, new ControlledDriveFeature(false), new RetailerSelection());

        // When
        $result = $loader->get('user-1', Retailer::default());

        // Then : coupé, la lecture répond quand même, avec le magasin
        $this->assertFalse($result->enabled);
        $this->assertNotNull($result->store);
    }

    public function testTheRowsOfAnotherRetailerAreIgnoredAndTheStoreIsThatOfTheRequestedOne(): void
    {
        // Given : un réglage chez une enseigne que ce code ne connaît pas (autre déploiement, enseigne retirée), posé directement
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO drive_settings (user_id, retailer, store_id, store_label, base_url, updated_at, product_writes_count) VALUES ('user-1', 'autre-test', '999', NULL, 'https://exemple.invalid/magasin-999', NOW(), 0)",
        );
        $this->entityManager->clear();

        // When
        $result = $this->loader->get('user-1', Retailer::default());

        // Then : seul le magasin de l'enseigne demandée, une seule entrée par enseigne connue, et la sélection ne voit pas l'inconnue
        $this->assertSame('123456', $result->store?->id);
        $this->assertCount(1, $result->retailers);
        $this->assertSame('123456', $result->retailers[0]->store?->id);
        $this->assertEquals(new RetailerSelectionDTO('AUTO', 'leclerc', ['leclerc']), $result->selection);
    }

    public function testReadingCostsTwoQueriesWhateverTheNumberOfRetailers(): void
    {
        // Given
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->withRetailer(Retailer::AUCHAN)->build());
        $this->preferences->save(RetailerPreference::create('user-1', new DateTimeImmutable('2026-10-30 10:00:00'))->chooseDefault(Retailer::AUCHAN, new DateTimeImmutable('2026-10-30 10:00:00')));
        $this->entityManager->clear();
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $this->loaderFor(['leclerc', 'auchan'])->get('user-1');

        // Then : les magasins, puis la préférence
        $this->assertSame(['SELECT drive_settings' => 1, 'SELECT drive_retailer_preference' => 1], $counter->byTable());
    }

    public function testReadingOnlyTheStoreCostsOneQueryAndReadsNeitherThePreferenceNorTheSelection(): void
    {
        // Given
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->entityManager->clear();
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $store = $this->loader->store('user-1', Retailer::default());

        // Then
        $this->assertSame(['SELECT drive_settings' => 1], $counter->byTable());
        $this->assertEquals(new DriveStoreDTO('123456', 'Ville-Test', 'https://exemple.leclercdrive.fr/magasin-123456-123456'), $store);
        $this->assertNull($this->loader->store('user-1', Retailer::AUCHAN));
        $this->assertNull($this->loader->store('user-2', Retailer::default()));
    }

    public function testTheSelectionAsksWhenTwoRetailersAreConfiguredWithoutADefaultAndPreselectsTheOldestStore(): void
    {
        // Given : Auchan enregistré le premier, puis Leclerc ; aucune préférence
        $this->twoStores();

        // When
        $result = $this->loaderFor(['leclerc', 'auchan'])->get('user-1');

        // Then : `ASK`, la première configurée est présélectionnée (la plus anciennement enregistrée), les deux sont candidates dans cet ordre
        $this->assertEquals(new RetailerSelectionDTO('ASK', 'auchan', ['auchan', 'leclerc']), $result->selection);
        $this->assertSame([null, null], [$result->defaultRetailer, $result->lastUsedRetailer]);
        // et les anciens champs sont ceux de la sélection, la liste des enseignes reste dans l'ordre du registre
        $this->assertSame(['auchan', self::AUCHAN_STORE], [$result->retailer, $result->store?->id]);
        $this->assertSame([['leclerc', true, false], ['auchan', true, false]], array_map(static fn(DriveRetailerDTO $retailer): array => [$retailer->retailer, $retailer->configured, $retailer->isDefault], $result->retailers));
    }

    public function testTheLastUsedRetailerIsPreselected(): void
    {
        // Given
        $this->twoStores();
        $this->preferences->save(RetailerPreference::create('user-1', new DateTimeImmutable('2026-10-30 10:00:00'))->rememberLastUsed(Retailer::LECLERC, new DateTimeImmutable('2026-10-30 10:00:00')));

        // When
        $result = $this->loaderFor(['leclerc', 'auchan'])->get('user-1');

        // Then
        $this->assertEquals(new RetailerSelectionDTO('ASK', 'leclerc', ['auchan', 'leclerc']), $result->selection);
        $this->assertSame([null, 'leclerc', 'leclerc'], [$result->defaultRetailer, $result->lastUsedRetailer, $result->retailer]);
    }

    public function testTheDefaultRetailerWinsOverTheLastUsedOneAndIsMarkedInTheList(): void
    {
        // Given : le drive habituel est Auchan, la dernière enseigne Leclerc
        $this->twoStores();
        $now = new DateTimeImmutable('2026-10-30 10:00:00');
        $this->preferences->save(RetailerPreference::create('user-1', $now)->chooseDefault(Retailer::AUCHAN, $now)->rememberLastUsed(Retailer::LECLERC, $now));

        // When
        $result = $this->loaderFor(['leclerc', 'auchan'])->get('user-1');

        // Then
        $this->assertEquals(new RetailerSelectionDTO('AUTO', 'auchan', ['auchan', 'leclerc']), $result->selection);
        $this->assertSame(['auchan', 'leclerc'], [$result->defaultRetailer, $result->lastUsedRetailer]);
        $this->assertSame([['leclerc', false], ['auchan', true]], array_map(static fn(DriveRetailerDTO $retailer): array => [$retailer->retailer, $retailer->isDefault], $result->retailers));
    }

    public function testADisabledDefaultRetailerIsIgnoredAndTheStoredPreferenceIsKept(): void
    {
        // Given : le drive habituel est Auchan, mais seule Leclerc est allumée
        $this->twoStores();
        $now = new DateTimeImmutable('2026-10-30 10:00:00');
        $this->preferences->save(RetailerPreference::create('user-1', $now)->chooseDefault(Retailer::AUCHAN, $now)->rememberLastUsed(Retailer::AUCHAN, $now));

        // When
        $result = $this->loaderFor(['leclerc'])->get('user-1');

        // Then : jamais une erreur, la sélection retombe sur la seule candidate et la préférence n'est pas montrée
        $this->assertEquals(new RetailerSelectionDTO('AUTO', 'leclerc', ['leclerc']), $result->selection);
        $this->assertSame([null, null, 'leclerc'], [$result->defaultRetailer, $result->lastUsedRetailer, $result->retailer]);
        $this->assertSame(['leclerc'], array_map(static fn(DriveRetailerDTO $retailer): string => $retailer->retailer, $result->retailers));
        $this->assertSame(Retailer::AUCHAN, $this->preferences->findOneByUser('user-1')?->getDefaultRetailer());

        // When : on rallume Auchan
        $again = $this->loaderFor(['leclerc', 'auchan'])->get('user-1');

        // Then : le drive habituel reparaît
        $this->assertSame(['auchan', 'AUTO'], [$again->defaultRetailer, $again->selection->mode]);
    }

    public function testAPreferenceForARetailerWithoutAStoreIsIgnored(): void
    {
        // Given : une préférence pointe vers une enseigne où la personne n'a (plus) de magasin
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $now = new DateTimeImmutable('2026-10-30 10:00:00');
        $this->preferences->save(RetailerPreference::create('user-1', $now)->chooseDefault(Retailer::AUCHAN, $now));

        // When
        $result = $this->loaderFor(['leclerc', 'auchan'])->get('user-1');

        // Then
        $this->assertEquals(new RetailerSelectionDTO('AUTO', 'leclerc', ['leclerc']), $result->selection);
        $this->assertNull($result->defaultRetailer);
    }

    public function testAnUnknownRetailerInThePreferenceReadsAsNone(): void
    {
        // Given : une valeur d'une enseigne retirée du registre, posée directement
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        $this->entityManager->getConnection()->executeStatement("INSERT INTO drive_retailer_preference (user_id, default_retailer, last_used_retailer, updated_at) VALUES ('user-1', 'autre-test', 'autre-test', NOW())");

        // When
        $result = $this->loader->get('user-1');

        // Then
        $this->assertSame([null, null, 'AUTO'], [$result->defaultRetailer, $result->lastUsedRetailer, $result->selection->mode]);
    }

    public function testTheSelectionNeverMixesTwoUsers(): void
    {
        // Given
        $this->twoStores();
        $now = new DateTimeImmutable('2026-10-30 10:00:00');
        $this->preferences->save(RetailerPreference::create('user-2', $now)->chooseDefault(Retailer::AUCHAN, $now));

        // When
        $result = $this->loaderFor(['leclerc', 'auchan'])->get('user-1');

        // Then
        $this->assertNull($result->defaultRetailer);
        $this->assertSame('ASK', $result->selection->mode);
    }

    /**
     * Auchan enregistrée la première, Leclerc ensuite, pour `user-1`.
     */
    private function twoStores(): void
    {
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->withRetailer(Retailer::AUCHAN)->withUpdatedAt(new DateTimeImmutable('2026-10-18 10:00:00'))->build());
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->withRetailer(Retailer::LECLERC)->withUpdatedAt(new DateTimeImmutable('2026-10-19 10:00:00'))->build());
    }

    /**
     * @param list<string> $retailers les enseignes allumées
     */
    private function loaderFor(array $retailers): DriveSettingsPostgreSQLLoader
    {
        return new DriveSettingsPostgreSQLLoader($this->entityManager, new ControlledDriveFeature(true, $retailers), new RetailerSelection());
    }
}
