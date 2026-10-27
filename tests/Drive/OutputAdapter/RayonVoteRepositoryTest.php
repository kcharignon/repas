<?php

namespace Repas\Tests\Drive\OutputAdapter;


use Repas\Drive\Domain\Interface\RayonVoteRepository;
use Repas\Drive\Domain\Model\RayonVote;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\SqlBudget\QueryCounter;

class RayonVoteRepositoryTest extends DatabaseTestCase
{
    private RayonVoteRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = static::getContainer()->get(RayonVoteRepository::class);
    }

    public function testAnAccountVotesOnceForEachPairWhateverTheNumberOfTimesItSendsIt(): void
    {
        // When : trois votes dont deux identiques dans une même liste, puis le même vote renvoyé plus tard avec un autre
        $this->repository->record('user-1', Retailer::LECLERC, [
            new RayonVote('boulangerie', 4001),
            new RayonVote('boulangerie', 4001),
            new RayonVote('epicerie', 4001),
        ]);
        $this->repository->record('user-1', Retailer::LECLERC, [new RayonVote('boulangerie', 4001), new RayonVote('boulangerie', 4002)]);

        // Then : une ligne par couple
        $this->assertSame(
            [['user-1', 'boulangerie', 4001], ['user-1', 'boulangerie', 4002], ['user-1', 'epicerie', 4001]],
            $this->rows(),
        );
    }

    public function testEachAccountHasItsOwnVote(): void
    {
        // When : deux comptes votent pour le même couple, un troisième pour un autre
        $this->repository->record('user-1', Retailer::LECLERC, [new RayonVote('boulangerie', 4001)]);
        $this->repository->record('user-2', Retailer::LECLERC, [new RayonVote('boulangerie', 4001)]);
        $this->repository->record('user-3', Retailer::LECLERC, [new RayonVote('boulangerie', 4002)]);

        // Then
        $this->assertSame(
            [['user-1', 'boulangerie', 4001], ['user-2', 'boulangerie', 4001], ['user-3', 'boulangerie', 4002]],
            $this->rows(),
        );
    }

    public function testAnEmptyListCostsNoQueryAndAnyListCostsOne(): void
    {
        // Given
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $this->repository->record('user-1', Retailer::LECLERC, []);

        // Then
        $this->assertSame([], $counter->byTable());

        // When : 60 votes de 60 couples différents
        $this->repository->record('user-1', Retailer::LECLERC, array_map(
            static fn(int $i): RayonVote => new RayonVote('rayon-' . intdiv($i, 3), 4000 + ($i % 3)),
            range(0, 59),
        ));

        // Then
        $this->assertSame(['INSERT drive_rayon_vote' => 1], $counter->byTable());
        $this->assertCount(60, $this->rows());
    }

    public function testAnAccountKeepsAtMostTheMaximumNumberOfPairsForARetailer(): void
    {
        // Given : un compte qui n'a plus que deux couples de place, un autre compte, et un compte qui a voté chez une autre enseigne
        $max = RayonVote::MAX_PER_USER_AND_RETAILER;
        $this->repository->record('user-1', Retailer::LECLERC, array_map(static fn(int $i): RayonVote => new RayonVote('rayon-' . $i, 4000 + $i), range(1, $max - 2)));
        $this->repository->record('user-2', Retailer::LECLERC, [new RayonVote('boulangerie', 4001)]);
        $this->entityManager->getConnection()->executeStatement("INSERT INTO drive_rayon_vote (user_id, retailer, department_slug, rayon_id) VALUES ('user-1', 'auchan', 'boulangerie', 1)");

        // When : un lot de cinq couples nouveaux et un couple déjà voté
        $this->repository->record('user-1', Retailer::LECLERC, [
            new RayonVote('rayon-1', 4001),
            ...array_map(static fn(int $i): RayonVote => new RayonVote('nouveau-' . $i, 9000 + $i), range(1, 5)),
        ]);

        // Then : seuls deux couples nouveaux sont gardés, le plafond est atteint
        $this->assertSame($max, $this->countFor('user-1', 'leclerc'));
        $this->assertSame(2, (int) $this->entityManager->getConnection()->fetchOne("SELECT count(*) FROM drive_rayon_vote WHERE user_id = 'user-1' AND department_slug LIKE 'nouveau-%'"));

        // When : de nouveaux couples encore, et un couple déjà voté
        $this->repository->record('user-1', Retailer::LECLERC, [new RayonVote('nouveau-9', 9009), new RayonVote('rayon-1', 4001)]);

        // Then : rien de plus ; les autres comptes et l'autre enseigne ne sont pas concernés
        $this->assertSame($max, $this->countFor('user-1', 'leclerc'));
        $this->assertSame(1, $this->countFor('user-1', 'auchan'));
        $this->assertSame(1, $this->countFor('user-2', 'leclerc'));

        // When : l'autre compte vote lui aussi
        $this->repository->record('user-2', Retailer::LECLERC, [new RayonVote('epicerie', 4002)]);

        // Then
        $this->assertSame(2, $this->countFor('user-2', 'leclerc'));
    }

    public function testErasingAnAccountRemovesOnlyItsVotes(): void
    {
        // Given
        $this->repository->record('departing', Retailer::LECLERC, [new RayonVote('boulangerie', 4001), new RayonVote('epicerie', 4002)]);
        $this->repository->record('staying', Retailer::LECLERC, [new RayonVote('boulangerie', 4001)]);
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $this->repository->deleteByUser('departing');

        // Then
        $this->assertSame(['DELETE drive_rayon_vote' => 1], $counter->byTable());
        $this->assertSame([['staying', 'boulangerie', 4001]], $this->rows());
    }

    public function testTheTableHoldsTheAccountToErasePerVoteAndNothingElse(): void
    {
        // When
        $columns = $this->entityManager->getConnection()->fetchFirstColumn(
            "SELECT column_name FROM information_schema.columns WHERE table_name = 'drive_rayon_vote' ORDER BY column_name",
        );

        // Then : le compte (effacé avec lui), l'enseigne, le rayon de Repas et le rayon de l'enseigne
        $this->assertSame(['department_slug', 'rayon_id', 'retailer', 'user_id'], $columns);
    }

    private function countFor(string $userId, string $retailer): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT count(*) FROM drive_rayon_vote WHERE user_id = ? AND retailer = ?', [$userId, $retailer]);
    }

    /**
     * @return list<array{string, string, int}> compte, rayon de Repas, rayon de l'enseigne
     */
    private function rows(): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative("SELECT user_id, department_slug, rayon_id FROM drive_rayon_vote WHERE retailer = 'leclerc' ORDER BY user_id, department_slug, rayon_id");

        return array_map(static fn(array $row): array => [$row['user_id'], $row['department_slug'], (int) $row['rayon_id']], $rows);
    }
}
