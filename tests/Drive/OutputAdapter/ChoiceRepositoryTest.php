<?php

namespace Repas\Tests\Drive\OutputAdapter;


use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Repas\Drive\Domain\Interface\ChoiceRepository;
use Repas\Drive\Domain\Model\Choice;
use Repas\Drive\Domain\Model\Retailer;
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
            static fn(int $i): Choice => new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug("ingredient-$i")->withAccepted(["a$i", "b$i"])->withRejected(["x$i"])->build(),
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
}
