<?php

namespace Repas\Tests\Drive\OutputAdapter;


use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Repas\Drive\Domain\Interface\ChoiceRepository;
use Repas\Drive\Domain\Model\Choice;
use Repas\Drive\Domain\Model\PackSize;
use Repas\Drive\Domain\Model\ProductObservation;
use Repas\Drive\Domain\Model\ProductPrice;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Domain\Model\SearchQuery;
use Repas\Tests\Helper\Builder\ChoiceBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\SqlBudget\QueryCounter;

class ChoiceRepositoryTest extends DatabaseTestCase
{
    private ChoiceRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = static::getContainer()->get(ChoiceRepository::class);
    }

    public function testASavedChoiceIsReadBackWithItsRankedAndRejectedProducts(): void
    {
        // Given
        $choice = new ChoiceBuilder()->withUserId('user-1')->withAccepted(['p-c', 'p-a', 'p-b'])->withRejected(['p-y', 'p-x'])
            ->withUpdatedAt(new DateTimeImmutable('2026-10-18 10:30:00'))->build();
        $this->repository->save($choice);

        // When
        $found = $this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'farine');

        // Then : les rangs sont l'ordre des produits acceptés, les refusés sont triés par identifiant
        $this->assertNotNull($found);
        $this->assertTrue($choice->isEqual($found));
        $this->assertSame(['p-c', 'p-a', 'p-b'], $found->getAcceptedProductIds());
        $this->assertSame(['p-x', 'p-y'], $found->getRejectedProductIds());
        $this->assertFalse($found->isHaveAtHome());
        $this->assertEquals(new DateTimeImmutable('2026-10-18 10:30:00'), $found->getUpdatedAt());
        $this->assertSame([1, 2, 3], array_map(static fn($accepted) => $accepted->rank, $found->getAccepted()));
    }

    public function testCountsThePreferencesOfOneUserInOneStoreInOneQuery(): void
    {
        // Given : deux préférences dans le magasin, une dans un autre, une chez un autre utilisateur
        $this->repository->saveAll([
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('farine')->withAccepted(['p-a'])->build(),
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('sel')->haveAtHome()->build(),
            new ChoiceBuilder()->withUserId('user-1')->withStoreId('654321')->withIngredientSlug('farine')->build(),
            new ChoiceBuilder()->withUserId('user-2')->withIngredientSlug('farine')->build(),
        ]);
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When / Then
        $this->assertSame(2, $this->repository->countByUserAndStore('user-1', Retailer::LECLERC, '123456'));
        $this->assertSame(['SELECT drive_choice' => 1], $counter->byTable());
        $this->assertSame(1, $this->repository->countByUserAndStore('user-1', Retailer::LECLERC, '654321'));
        $this->assertSame(0, $this->repository->countByUserAndStore('user-3', Retailer::LECLERC, '123456'));
    }

    public function testRewritingTheRanksNeverBreaksTheUniqueRank(): void
    {
        // Given
        $this->repository->save(new ChoiceBuilder()->withId('choice-1')->withUserId('user-1')->withAccepted(['a', 'b', 'c'])->build());
        $choice = $this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'farine');

        // When : un nouveau préféré décale tous les rangs, puis un refus recompacte
        $choice->addPrimary('d')->reject('b');
        $this->repository->save($choice);

        // Then
        $found = $this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'farine');
        $this->assertSame(['d', 'a', 'c'], $found->getAcceptedProductIds());
        $this->assertSame(['b'], $found->getRejectedProductIds());
        $this->assertSame(4, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_choice_product'));
    }

    public function testHaveAtHomeLeavesNoLine(): void
    {
        // Given
        $this->repository->save(new ChoiceBuilder()->withId('choice-1')->withUserId('user-1')->withAccepted(['a', 'b'])->withRejected(['x'])->build());
        $choice = $this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'farine');

        // When
        $this->repository->save($choice->markHaveAtHome());

        // Then
        $found = $this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'farine');
        $this->assertTrue($found->isHaveAtHome());
        $this->assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_choice_product'));
    }

    public function testTwoChoicesMayUseTheSameRank(): void
    {
        // When
        $this->repository->saveAll([
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('farine')->withAccepted(['a', 'b'])->build(),
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('sel')->withAccepted(['a', 'c'])->withRejected(['x'])->build(),
        ]);

        // Then
        $this->assertSame(['a', 'b'], $this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'farine')->getAcceptedProductIds());
        $this->assertSame(['a', 'c'], $this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'sel')->getAcceptedProductIds());
    }

    public function testThereIsOneChoicePerUserStoreAndIngredient(): void
    {
        // Given
        $this->repository->save(new ChoiceBuilder()->withUserId('user-1')->build());

        // Then
        $this->expectException(UniqueConstraintViolationException::class);

        // When : un autre identifiant pour la même combinaison
        $this->repository->save(new ChoiceBuilder()->withUserId('user-1')->build());
    }

    public function testFindByUserStoreAndSlugsKeepsToTheUserTheStoreAndTheSlugs(): void
    {
        // Given
        $this->repository->saveAll([
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('farine')->withAccepted(['a'])->build(),
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('sel')->withAccepted(['b'])->withRejected(['x'])->build(),
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('sucre')->build(),
            new ChoiceBuilder()->withUserId('user-1')->withStoreId('654321')->withIngredientSlug('farine')->build(),
            new ChoiceBuilder()->withUserId('user-2')->withIngredientSlug('farine')->build(),
        ]);

        // When
        $found = $this->repository->findByUserStoreAndSlugs('user-1', Retailer::LECLERC, '123456', ['farine', 'sel', 'poivre']);

        // Then
        $bySlug = [];
        foreach ($found as $choice) {
            $bySlug[$choice->getIngredientSlug()] = $choice;
        }
        ksort($bySlug);
        $this->assertSame(['farine', 'sel'], array_keys($bySlug));
        $this->assertSame(['a'], $bySlug['farine']->getAcceptedProductIds());
        $this->assertSame(['b'], $bySlug['sel']->getAcceptedProductIds());
        $this->assertSame(['x'], $bySlug['sel']->getRejectedProductIds());
        $this->assertSame([], $this->repository->findByUserStoreAndSlugs('user-1', Retailer::LECLERC, '123456', []));
        $this->assertNull($this->repository->findOne('user-3', Retailer::LECLERC, '123456', 'farine'));
    }

    public function testReadingAndWritingABatchCostsAFixedNumberOfQueries(): void
    {
        // Given
        $choices = array_map(
            static fn(int $i): Choice => new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug("ingredient-$i")->withAccepted(["a$i", "b$i"])->withRejected(["x$i"])->withSearchQuery("recherche $i")->build(),
            range(1, 40),
        );
        $slugs = array_map(static fn(Choice $choice): string => $choice->getIngredientSlug(), $choices);
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $this->repository->saveAll($choices);
        $found = $this->repository->findByUserStoreAndSlugs('user-1', Retailer::LECLERC, '123456', $slugs);

        // Then : une écriture par table, un effacement des anciennes lignes, une lecture avec les lignes
        $this->assertCount(40, $found);
        $this->assertSame(['INSERT drive_choice' => 1, 'DELETE drive_choice_product' => 1, 'INSERT drive_choice_product' => 1, 'SELECT drive_choice' => 1], $counter->byTable());
    }

    public function testDeleteRemovesTheChoiceAndItsLinesOnly(): void
    {
        // Given
        $this->repository->saveAll([
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('farine')->withAccepted(['a'])->withRejected(['x'])->build(),
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('sel')->withAccepted(['b'])->build(),
        ]);

        // When
        $this->repository->delete($this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'farine'));

        // Then
        $this->assertNull($this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'farine'));
        $this->assertNotNull($this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'sel'));
        $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_choice_product'));
    }

    public function testPurgingAUserLeavesNoOrphanLineAndKeepsTheOthers(): void
    {
        // Given
        $this->repository->saveAll([
            new ChoiceBuilder()->withUserId('departing')->withIngredientSlug('farine')->withAccepted(['a', 'b'])->withRejected(['x'])->build(),
            new ChoiceBuilder()->withUserId('departing')->withStoreId('654321')->withIngredientSlug('sel')->withAccepted(['c'])->build(),
            new ChoiceBuilder()->withUserId('staying')->withIngredientSlug('farine')->withAccepted(['a'])->withRejected(['y'])->build(),
        ]);

        // When
        $this->repository->deleteProductsByUser('departing');
        $this->repository->deleteByUser('departing');

        // Then : aucune ligne orpheline, rien de touché chez l'autre
        $connection = $this->entityManager->getConnection();
        $this->assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM drive_choice WHERE user_id = 'departing'"));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM drive_choice_product WHERE choice_id NOT IN (SELECT id FROM drive_choice)'));
        $this->assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM drive_choice_product'));
        $this->assertSame(['a'], $this->repository->findOne('staying', Retailer::LECLERC, '123456', 'farine')->getAcceptedProductIds());
    }

    public function testReplacingTheListsRewritesTheLinesWithoutBreakingTheUniqueRank(): void
    {
        // Given
        $this->repository->save(new ChoiceBuilder()->withId('choice-1')->withUserId('user-1')->withAccepted(['a', 'b', 'c'])->withRejected(['x', 'y'])->build());
        $choice = $this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'farine');

        // When : l'ordre s'inverse, c est retiré, y n'est plus refusé, z l'est
        $this->repository->save($choice->replace(false, ['b', 'a'], ['x', 'z']));

        // Then
        $found = $this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'farine');
        $this->assertSame(['b', 'a'], $found->getAcceptedProductIds());
        $this->assertSame(['x', 'z'], $found->getRejectedProductIds());
        $this->assertSame(4, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_choice_product'));

        // When : « j'en ai toujours »
        $this->repository->save($found->replace(true, [], []));

        // Then : plus aucune ligne de produit, la préférence reste
        $this->assertTrue($this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'farine')->isHaveAtHome());
        $this->assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_choice_product'));
    }

    public function testTheSearchThatFoundTheProductIsSavedReadBackReplacedAndForgotten(): void
    {
        // Given
        $this->repository->save(new ChoiceBuilder()->withId('choice-1')->withUserId('user-1')->withAccepted(['a'])->withSearchQuery('céréales coussins')->build());

        // When
        $found = $this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'farine');

        // Then
        $this->assertSame('céréales coussins', $found->getSearchQuery()?->value);

        // When : un autre produit, sans toucher à la recherche
        $this->repository->save($found->addPrimary('b'));

        // Then : elle est gardée
        $found = $this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'farine');
        $this->assertSame('céréales coussins', $found->getSearchQuery()?->value);

        // When : remplacée
        $this->repository->save($found->rememberSearch(SearchQuery::of('coussins chocolat')));

        // Then
        $found = $this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'farine');
        $this->assertSame('coussins chocolat', $found->getSearchQuery()?->value);

        // When : oubliée
        $this->repository->save($found->forgetSearch());

        // Then
        $this->assertNull($this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'farine')->getSearchQuery());
        $this->assertNull($this->entityManager->getConnection()->fetchOne('SELECT search_query FROM drive_choice WHERE id = ?', ['choice-1']));
    }

    public function testAChoiceWithoutSearchIsStoredWithNull(): void
    {
        // When
        $this->repository->save(new ChoiceBuilder()->withId('choice-1')->withUserId('user-1')->withAccepted(['a'])->build());

        // Then
        $this->assertNull($this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'farine')->getSearchQuery());
        $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_choice WHERE id = ? AND search_query IS NULL', ['choice-1']));
    }

    public function testHavingItAtHomeErasesTheSearchInTheRow(): void
    {
        // Given
        $this->repository->save(new ChoiceBuilder()->withId('choice-1')->withUserId('user-1')->withAccepted(['a'])->withSearchQuery('céréales coussins')->build());
        $choice = $this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'farine');

        // When
        $this->repository->save($choice->markHaveAtHome());

        // Then : plus rien dans la ligne
        $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_choice WHERE id = ? AND search_query IS NULL', ['choice-1']));
    }

    public function testAnOldRowLeftHaveAtHomeWithASearchIsReadWithoutIt(): void
    {
        // Given : une version plus ancienne du code a repassé la ligne en « j'en ai toujours » sans effacer la colonne
        $this->repository->save(new ChoiceBuilder()->withId('choice-1')->withUserId('user-1')->withAccepted(['a'])->withSearchQuery('céréales coussins')->build());
        $this->entityManager->getConnection()->executeStatement("UPDATE drive_choice SET kind = 'HAVE_AT_HOME' WHERE id = 'choice-1'");

        // When
        $found = $this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'farine');

        // Then
        $this->assertTrue($found->isHaveAtHome());
        $this->assertNull($found->getSearchQuery());
    }

    public function testTheSearchIsKeptPerRetailerStoreAndUser(): void
    {
        // Given : la même combinaison utilisateur, magasin et ingrédient chez une autre enseigne (écrite en SQL : une seule enseigne
        // est connue du code), puis un autre magasin et un autre utilisateur
        $this->repository->saveAll([
            new ChoiceBuilder()->withId('leclerc')->withUserId('user-1')->withAccepted(['a'])->withSearchQuery('recherche pour Leclerc')->build(),
            new ChoiceBuilder()->withId('other-store')->withUserId('user-1')->withStoreId('654321')->withAccepted(['a'])->withSearchQuery('recherche de l\'autre magasin')->build(),
            new ChoiceBuilder()->withId('other-user')->withUserId('user-2')->withAccepted(['a'])->withSearchQuery('recherche de user-2')->build(),
        ]);
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO drive_choice (id, user_id, retailer, store_id, ingredient_slug, kind, search_query, updated_at) VALUES ('other-retailer', 'user-1', 'autre-enseigne', '123456', 'farine', 'PRODUCTS', 'recherche pour une autre enseigne', '2026-10-18 10:00:00')",
        );

        // When : Leclerc change sa recherche
        $choice = $this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'farine');
        $this->repository->save($choice->rememberSearch(SearchQuery::of('nouvelle recherche pour Leclerc')));

        // Then : chaque clé garde la sienne, rien n'est repris ni écrasé d'une enseigne, d'un magasin ou d'un utilisateur à l'autre
        $searches = $this->entityManager->getConnection()->fetchAllKeyValue('SELECT id, search_query FROM drive_choice ORDER BY id');
        $this->assertSame([
            'leclerc' => 'nouvelle recherche pour Leclerc',
            'other-retailer' => 'recherche pour une autre enseigne',
            'other-store' => 'recherche de l\'autre magasin',
            'other-user' => 'recherche de user-2',
        ], $searches);
    }

    public function testPurgingAUserErasesTheirRememberedSearches(): void
    {
        // Given
        $this->repository->saveAll([
            new ChoiceBuilder()->withUserId('departing')->withIngredientSlug('farine')->withAccepted(['a'])->withSearchQuery('céréales coussins')->build(),
            new ChoiceBuilder()->withUserId('departing')->withStoreId('654321')->withIngredientSlug('sel')->withAccepted(['c'])->withSearchQuery('gros sel')->build(),
            // DRV-653 : une préférence qui ne garde que la recherche (aucun produit) part elle aussi
            new ChoiceBuilder()->withUserId('departing')->withIngredientSlug('cereale')->withSearchQuery('céréales coussins')->build(),
            new ChoiceBuilder()->withUserId('staying')->withIngredientSlug('farine')->withAccepted(['a'])->withSearchQuery('farine de blé')->build(),
        ]);

        // When
        $this->repository->deleteProductsByUser('departing');
        $this->repository->deleteByUser('departing');

        // Then : la recherche est dans la ligne du choix, elle part avec elle ; celle de l'autre reste
        $this->assertSame(['farine de blé'], $this->entityManager->getConnection()->fetchFirstColumn('SELECT search_query FROM drive_choice'));
    }

    public function testThePackSizeOfEachProductIsSavedReadBackReplacedAndForgotten(): void
    {
        // Given : deux produits acceptés, le premier compté en 20 tranches, un refusé
        $this->repository->save(new ChoiceBuilder()->withId('choice-1')->withUserId('user-1')->withIngredientSlug('cheddar')
            ->withAccepted(['a', 'b'])->withRejected(['x'])->withPackSize('a', 20, 'tranche')->build());

        // When
        $found = $this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'cheddar');

        // Then : une contenance par produit
        $this->assertEquals(PackSize::of(20, 'tranche'), $found->getPackSize('a'));
        $this->assertNull($found->getPackSize('b'));
        $this->assertSame([
            ['a', '20.000', 'tranche'],
            ['b', null, null],
        ], array_map(static fn(array $row): array => array_values($row), $this->entityManager->getConnection()->fetchAllAssociative(
            "SELECT product_id, pack_size_value, pack_size_unit_slug FROM drive_choice_product WHERE verdict = 'ACCEPTED' ORDER BY rank",
        )));
        $this->assertSame([[null, null]], array_map(static fn(array $row): array => array_values($row), $this->entityManager->getConnection()->fetchAllAssociative(
            "SELECT pack_size_value, pack_size_unit_slug FROM drive_choice_product WHERE verdict = 'REJECTED'",
        )));

        // When : un nouveau préféré décale les rangs, la contenance suit son produit
        $this->repository->save($found->addPrimary('c')->rememberPackSize('c', PackSize::of(12.5, 'tranche')));

        // Then
        $found = $this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'cheddar');
        $this->assertSame(['c', 'a', 'b'], $found->getAcceptedProductIds());
        $this->assertEquals(PackSize::of(12.5, 'tranche'), $found->getPackSize('c'));
        $this->assertEquals(PackSize::of(20, 'tranche'), $found->getPackSize('a'));
        $this->assertNull($found->getPackSize('b'));

        // When : remplacée, puis oubliée
        $this->repository->save($found->rememberPackSize('a', PackSize::of(24, 'tranche'))->forgetPackSize('c'));

        // Then
        $found = $this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'cheddar');
        $this->assertEquals(PackSize::of(24, 'tranche'), $found->getPackSize('a'));
        $this->assertNull($found->getPackSize('c'));
    }

    public function testThePackSizeKeepsItsThreeDecimalsAndItsBounds(): void
    {
        // Given / When
        $this->repository->save(new ChoiceBuilder()->withId('choice-1')->withUserId('user-1')->withAccepted(['a', 'b', 'c'])
            ->withPackSize('a', 0.001, 'tranche')->withPackSize('b', 9999, 'tranche')->withPackSize('c', 1234.567, 'cuillere-a-soupe')->build());

        // Then
        $found = $this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'farine');
        $this->assertEquals(PackSize::of(0.001, 'tranche'), $found->getPackSize('a'));
        $this->assertEquals(PackSize::of(9999, 'tranche'), $found->getPackSize('b'));
        $this->assertEquals(PackSize::of(1234.567, 'cuillere-a-soupe'), $found->getPackSize('c'));
    }

    public function testAProductThatIsNoLongerAcceptedLeavesNoPackSizeInTheTable(): void
    {
        // Given
        $this->repository->save(new ChoiceBuilder()->withId('choice-1')->withUserId('user-1')->withAccepted(['a', 'b'])->withPackSize('a', 20, 'tranche')->withPackSize('b', 12, 'tranche')->build());
        $choice = $this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'farine');

        // When : a est refusé, b retiré
        $this->repository->save($choice->reject('a')->remove('b'));

        // Then : les lignes sont réécrites, aucune contenance ne reste
        $this->assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_choice_product WHERE pack_size_value IS NOT NULL OR pack_size_unit_slug IS NOT NULL'));
    }

    public function testHavingItAtHomeErasesThePackSizesInTheTable(): void
    {
        // Given
        $this->repository->save(new ChoiceBuilder()->withId('choice-1')->withUserId('user-1')->withAccepted(['a'])->withPackSize('a', 20, 'tranche')->build());
        $choice = $this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'farine');

        // When
        $this->repository->save($choice->markHaveAtHome());

        // Then
        $this->assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_choice_product'));
    }

    public function testARowWithOnlyHalfOfAPackSizeIsReadWithoutIt(): void
    {
        // Given : une ligne abîmée à la main (les deux colonnes vont ensemble)
        $this->repository->save(new ChoiceBuilder()->withId('choice-1')->withUserId('user-1')->withAccepted(['a', 'b'])->build());
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement("UPDATE drive_choice_product SET pack_size_value = 20 WHERE product_id = 'a'");
        $connection->executeStatement("UPDATE drive_choice_product SET pack_size_unit_slug = 'tranche' WHERE product_id = 'b'");

        // When
        $found = $this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'farine');

        // Then
        $this->assertNull($found->getPackSize('a'));
        $this->assertNull($found->getPackSize('b'));
    }

    public function testThePackSizeBelongsToTheChoiceOfTheUserAndNotToTheProduct(): void
    {
        // Given : le même produit chez deux utilisateurs et dans deux préférences d'un même utilisateur
        $this->repository->saveAll([
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('cheddar')->withAccepted(['shared'])->withPackSize('shared', 20, 'tranche')->build(),
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('emmental')->withAccepted(['shared'])->build(),
            new ChoiceBuilder()->withUserId('user-2')->withIngredientSlug('cheddar')->withAccepted(['shared'])->withPackSize('shared', 30, 'tranche')->build(),
        ]);

        // When : user-1 change sa réponse
        $choice = $this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'cheddar');
        $this->repository->save($choice->rememberPackSize('shared', PackSize::of(24, 'tranche')));

        // Then
        $this->assertEquals(PackSize::of(24, 'tranche'), $this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'cheddar')->getPackSize('shared'));
        $this->assertNull($this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'emmental')->getPackSize('shared'));
        $this->assertEquals(PackSize::of(30, 'tranche'), $this->repository->findOne('user-2', Retailer::LECLERC, '123456', 'cheddar')->getPackSize('shared'));
    }

    public function testPurgingAUserErasesTheirPackSizes(): void
    {
        // Given
        $this->repository->saveAll([
            new ChoiceBuilder()->withUserId('departing')->withIngredientSlug('cheddar')->withAccepted(['a'])->withPackSize('a', 20, 'tranche')->build(),
            new ChoiceBuilder()->withUserId('departing')->withStoreId('654321')->withIngredientSlug('emmental')->withAccepted(['c'])->withPackSize('c', 15, 'tranche')->build(),
            new ChoiceBuilder()->withUserId('staying')->withIngredientSlug('cheddar')->withAccepted(['a'])->withPackSize('a', 30, 'tranche')->build(),
        ]);

        // When
        $this->repository->deleteProductsByUser('departing');
        $this->repository->deleteByUser('departing');

        // Then : la contenance est dans la ligne du produit, elle part avec elle ; celle de l'autre reste
        $this->assertSame(
            [['30.000', 'tranche']],
            array_map(static fn(array $row): array => array_values($row), $this->entityManager->getConnection()->fetchAllAssociative('SELECT pack_size_value, pack_size_unit_slug FROM drive_choice_product')),
        );
    }

    public function testForgettingAChoiceErasesItsPackSizes(): void
    {
        // Given
        $this->repository->save(new ChoiceBuilder()->withId('choice-1')->withUserId('user-1')->withAccepted(['a'])->withPackSize('a', 20, 'tranche')->build());

        // When
        $this->repository->delete($this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'farine'));

        // Then
        $this->assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_choice_product'));
    }

    public function testWritingABatchWithPackSizesCostsAFixedNumberOfQueries(): void
    {
        // Given : 30 préférences de trois produits, chacun avec sa contenance
        $choices = [];
        foreach (range(1, 30) as $i) {
            $choices[] = new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug("ingredient-$i")->withAccepted(["a$i", "b$i", "c$i"])
                ->withPackSize("a$i", $i, 'tranche')->withPackSize("c$i", 2 * $i, 'tranche')->build();
        }
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $this->repository->saveAll($choices);

        // Then : une requête par table, plus la suppression des lignes (comme sans contenance)
        $this->assertSame(['INSERT drive_choice' => 1, 'DELETE drive_choice_product' => 1, 'INSERT drive_choice_product' => 1], $counter->byTable());
    }

    public function testTheValuesObservedByTheAccountAreSavedOnTheLineOfEachProductAndReadBack(): void
    {
        // Given : deux produits acceptés (le premier avec un libellé et un prix, le second sans rien) et un refusé avec son libellé
        $seenAt = new DateTimeImmutable('2026-10-28 09:30:00');
        $this->repository->save(new ChoiceBuilder()->withId('choice-1')->withUserId('user-1')->withAccepted(['a', 'b'])->withRejected(['x'])
            ->withObservation('a', 'Farine T55 1 kg', 149, $seenAt)->withObservation('x', 'Farine complète bio')->build());

        // When
        $found = $this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'farine');

        // Then : une ligne, une observation
        $this->assertSame(['Farine T55 1 kg', 149], [$found->getObservation('a')->label, $found->getObservation('a')->priceCents]);
        $this->assertEquals($seenAt, $found->getObservation('a')->priceSeenAt);
        $this->assertNull($found->getObservation('b'));
        $this->assertSame(['Farine complète bio', null, null], [$found->getObservation('x')->label, $found->getObservation('x')->priceCents, $found->getObservation('x')->priceSeenAt]);
        $this->assertSame(
            [['a', 'Farine T55 1 kg', 149, '2026-10-28 09:30:00'], ['b', null, null, null], ['x', 'Farine complète bio', null, null]],
            array_map(static fn(array $row): array => array_values($row), $this->entityManager->getConnection()->fetchAllAssociative(
                'SELECT product_id, label, price_cents, price_seen_at::text FROM drive_choice_product ORDER BY product_id',
            )),
        );
    }

    public function testRewritingThePreferenceKeepsTheValuesOfTheProductsThatStay(): void
    {
        // Given
        $this->repository->save(new ChoiceBuilder()->withId('choice-1')->withUserId('user-1')->withAccepted(['a', 'b'])
            ->withObservation('a', 'Produit a', 149)->withObservation('b', 'Produit b', 99)->build());
        $choice = $this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'farine');

        // When : un nouveau préféré décale les rangs, le produit b est refusé
        $this->repository->save($choice->addPrimary('c')->reject('b'));

        // Then : a garde les siennes, b ne garde que son libellé, c n'en a pas
        $found = $this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'farine');
        $this->assertSame(['Produit a', 149], [$found->getObservation('a')->label, $found->getObservation('a')->priceCents]);
        $this->assertSame(['Produit b', null], [$found->getObservation('b')->label, $found->getObservation('b')->priceCents]);
        $this->assertNull($found->getObservation('c'));
        $this->assertSame(0, (int) $this->entityManager->getConnection()->fetchOne("SELECT COUNT(*) FROM drive_choice_product WHERE verdict = 'REJECTED' AND (price_cents IS NOT NULL OR price_seen_at IS NOT NULL)"));
    }

    public function testAProductThatLeavesTheListsAndHavingItAtHomeLeaveNoValueInTheTable(): void
    {
        // Given
        $this->repository->save(new ChoiceBuilder()->withId('choice-1')->withUserId('user-1')->withAccepted(['a', 'b'])
            ->withObservation('a', 'Produit a', 149)->withObservation('b', 'Produit b', 99)->build());
        $choice = $this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'farine');

        // When : a n'est plus ni accepté ni refusé
        $this->repository->save($choice->remove('a'));

        // Then
        $this->assertSame([['b', 'Produit b']], array_map(static fn(array $row): array => array_values($row), $this->entityManager->getConnection()->fetchAllAssociative('SELECT product_id, label FROM drive_choice_product')));

        // When
        $this->repository->save($this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'farine')->markHaveAtHome());

        // Then
        $this->assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_choice_product'));
    }

    public function testARowWithOnlyHalfOfAPriceIsReadWithoutThePrice(): void
    {
        // Given : une ligne abîmée à la main (le prix et sa date vont ensemble)
        $this->repository->save(new ChoiceBuilder()->withId('choice-1')->withUserId('user-1')->withAccepted(['a', 'b'])->withObservation('a', 'Produit a')->withObservation('b', 'Produit b')->build());
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement("UPDATE drive_choice_product SET price_cents = 149 WHERE product_id = 'a'");
        $connection->executeStatement("UPDATE drive_choice_product SET price_seen_at = '2026-10-28 09:30:00' WHERE product_id = 'b'");

        // When
        $found = $this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'farine');

        // Then : les libellés restent, aucun prix à moitié écrit n'est lu
        $this->assertSame(['Produit a', null, null], [$found->getObservation('a')->label, $found->getObservation('a')->priceCents, $found->getObservation('a')->priceSeenAt]);
        $this->assertSame(['Produit b', null, null], [$found->getObservation('b')->label, $found->getObservation('b')->priceCents, $found->getObservation('b')->priceSeenAt]);
    }

    public function testTheValuesBelongToTheChoiceOfTheUserAndNotToTheProduct(): void
    {
        // Given : le même produit chez deux utilisateurs et dans deux préférences d'un même utilisateur
        $this->repository->saveAll([
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('cheddar')->withAccepted(['shared'])->withObservation('shared', 'Cheddar 20 tranches', 349)->build(),
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('emmental')->withAccepted(['shared'])->build(),
            new ChoiceBuilder()->withUserId('user-2')->withIngredientSlug('cheddar')->withAccepted(['shared'])->withObservation('shared', 'Cheddar – lot de 2', 299)->build(),
        ]);

        // When : user-1 relit son prix
        $choice = $this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'cheddar');
        $this->repository->save($choice->observePrice('shared', new ProductPrice(359), new DateTimeImmutable('2026-10-29 09:00:00')));

        // Then : seule sa ligne a changé
        $this->assertSame(['Cheddar 20 tranches', 359], [$this->observationOf('user-1', 'cheddar')->label, $this->observationOf('user-1', 'cheddar')->priceCents]);
        $this->assertNull($this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'emmental')->getObservation('shared'));
        $this->assertSame(['Cheddar – lot de 2', 299], [$this->observationOf('user-2', 'cheddar')->label, $this->observationOf('user-2', 'cheddar')->priceCents]);
    }

    public function testPurgingAUserErasesTheirValuesAndKeepsTheOthers(): void
    {
        // Given
        $this->repository->saveAll([
            new ChoiceBuilder()->withUserId('departing')->withIngredientSlug('cheddar')->withAccepted(['a'])->withRejected(['x'])->withObservation('a', 'Cheddar', 349)->withObservation('x', 'Bloc')->build(),
            new ChoiceBuilder()->withUserId('departing')->withStoreId('654321')->withIngredientSlug('emmental')->withAccepted(['c'])->withObservation('c', 'Emmental', 199)->build(),
            new ChoiceBuilder()->withUserId('staying')->withIngredientSlug('cheddar')->withAccepted(['a'])->withObservation('a', 'Cheddar du voisin', 299)->build(),
        ]);

        // When
        $this->repository->deleteProductsByUser('departing');
        $this->repository->deleteByUser('departing');

        // Then : les valeurs sont dans la ligne du produit, elles partent avec elle ; celles de l'autre compte restent
        $this->assertSame(
            [['Cheddar du voisin', 299]],
            array_map(static fn(array $row): array => array_values($row), $this->entityManager->getConnection()->fetchAllAssociative('SELECT label, price_cents FROM drive_choice_product')),
        );
    }

    public function testForgettingAChoiceErasesItsValues(): void
    {
        // Given
        $this->repository->save(new ChoiceBuilder()->withId('choice-1')->withUserId('user-1')->withAccepted(['a'])->withObservation('a', 'Produit a', 149)->build());

        // When
        $this->repository->delete($this->repository->findOne('user-1', Retailer::LECLERC, '123456', 'farine'));

        // Then
        $this->assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_choice_product'));
    }

    public function testWritingABatchWithValuesCostsAFixedNumberOfQueries(): void
    {
        // Given : 30 préférences de trois produits, chacun avec ses valeurs
        $choices = [];
        foreach (range(1, 30) as $i) {
            $choices[] = new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug("ingredient-$i")->withAccepted(["a$i", "b$i", "c$i"])->withRejected(["x$i"])
                ->withObservation("a$i", "Produit a$i", 100 + $i)->withObservation("c$i", "Produit c$i", 200 + $i)->withObservation("x$i", "Refus $i")->build();
        }
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $this->repository->saveAll($choices);

        // Then : les valeurs voyagent dans les requêtes qui existent déjà
        $this->assertSame(['INSERT drive_choice' => 1, 'DELETE drive_choice_product' => 1, 'INSERT drive_choice_product' => 1], $counter->byTable());
    }

    private function observationOf(string $userId, string $slug): ProductObservation
    {
        return $this->repository->findOne($userId, Retailer::LECLERC, '123456', $slug)->getObservation('shared');
    }
}
