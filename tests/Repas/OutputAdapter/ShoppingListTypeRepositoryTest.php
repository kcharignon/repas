<?php

namespace Repas\Tests\Repas\OutputAdapter;


use DateTimeImmutable;
use Repas\Repas\Domain\Exception\ShoppingListTypeException;
use Repas\Repas\Domain\Interface\ShoppingListTypeRepository;
use Repas\Repas\Domain\Model\ShoppingListType;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\SqlBudget\QueryCounter;

/**
 * Les types de liste de courses (ADR 0036) et leurs rattachements de rayons sur la VRAIE base. Aucune clé étrangère : les utilisateurs et les
 * rayons n'ont pas besoin d'exister, chaque test écrit ses propres lignes.
 */
class ShoppingListTypeRepositoryTest extends DatabaseTestCase
{
    private const string USER = 'user-id';
    private const string OTHER = 'other-id';

    private ShoppingListTypeRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = static::getContainer()->get(ShoppingListTypeRepository::class);
    }

    public function testATypeIsWrittenAndReadBackWithItsOwnerNameDateAndDepartments(): void
    {
        // Given
        $type = ShoppingListType::create('type-1', self::USER, 'Marché', new DateTimeImmutable('2026-10-31 10:00:00'))->replaceDepartments(['legumes', 'fruits']);

        // When
        $this->repository->save($type);
        $read = $this->repository->findOneById('type-1');

        // Then
        $this->assertSame(['type-1', self::USER, 'Marché', 'marche', ['fruits', 'legumes']], [$read->getId(), $read->getOwnerId(), $read->getName(), $read->getSluggedName(), $read->getDepartmentSlugs()]);
        $this->assertSame('2026-10-31 10:00:00', $read->getCreatedAt()->format('Y-m-d H:i:s'));
    }

    public function testAnUnknownTypeIsNotFound(): void
    {
        // Then
        $this->expectExceptionObject(ShoppingListTypeException::notFound());

        // When
        $this->repository->findOneById('inconnu');
    }

    public function testSavingAgainRenamesTheTypeAndNeverChangesItsOwnerOrDate(): void
    {
        // Given
        $this->repository->save(ShoppingListType::create('type-1', self::USER, 'Boucherie', new DateTimeImmutable('2026-10-31 10:00:00')));

        // When : le modèle relu, renommé, enregistré (un modèle forgé à un autre propriétaire ne déplace pas la ligne)
        $read = $this->repository->findOneById('type-1');
        $this->repository->save($read->rename('Charcuterie'));
        $this->repository->save(ShoppingListType::create('type-1', self::OTHER, 'Volée', new DateTimeImmutable('2030-01-01 00:00:00')));
        $final = $this->repository->findOneById('type-1');

        // Then
        $this->assertSame([self::USER, '2026-10-31 10:00:00'], [$final->getOwnerId(), $final->getCreatedAt()->format('Y-m-d H:i:s')]);
        $this->assertSame('Volée', $final->getName());
    }

    public function testTheDepartmentsAreReplacedAsAWhole(): void
    {
        // Given
        $type = ShoppingListType::create('type-1', self::USER, 'Boucherie', new DateTimeImmutable())->replaceDepartments(['viandes', 'poissons']);
        $this->repository->save($type);

        // When
        $this->repository->save($type->replaceDepartments(['poissons', 'fromages']));

        // Then
        $this->assertSame(['fromages', 'poissons'], $this->repository->findOneById('type-1')->getDepartmentSlugs());
        $this->assertSame(0, $this->attachmentsOf('viandes'));
    }

    public function testNoDepartmentLeavesTheTypeWithoutAnyAttachment(): void
    {
        // Given
        $type = ShoppingListType::create('type-1', self::USER, 'Boucherie', new DateTimeImmutable())->replaceDepartments(['viandes']);
        $this->repository->save($type);

        // When
        $this->repository->save($type->replaceDepartments([]));

        // Then
        $this->assertSame([], $this->repository->findOneById('type-1')->getDepartmentSlugs());
    }

    public function testADepartmentTakenByAnotherTypeOfTheSameUserIsMovedAndOneLineStays(): void
    {
        // Given
        $butcher = ShoppingListType::create('butcher', self::USER, 'Boucherie', new DateTimeImmutable('2026-10-31 10:00:00'))->replaceDepartments(['viandes', 'poissons']);
        $market = ShoppingListType::create('market', self::USER, 'Marché', new DateTimeImmutable('2026-10-31 11:00:00'));
        $this->repository->save($butcher);
        $this->repository->save($market);

        // When
        $this->repository->save($market->replaceDepartments(['legumes', 'viandes']));

        // Then : un rayon est dans un seul type à la fois
        $this->assertSame(['poissons'], $this->repository->findOneById('butcher')->getDepartmentSlugs());
        $this->assertSame(['legumes', 'viandes'], $this->repository->findOneById('market')->getDepartmentSlugs());
        $this->assertSame(1, $this->attachmentsOf('viandes'));
    }

    public function testTheSameDepartmentCanBeAttachedByTwoUsersToTheirOwnTypes(): void
    {
        // Given
        $mine = ShoppingListType::create('mine', self::USER, 'Boucherie', new DateTimeImmutable())->replaceDepartments(['viandes']);
        $theirs = ShoppingListType::create('theirs', self::OTHER, 'Boucherie', new DateTimeImmutable());

        // When
        $this->repository->save($mine);
        $this->repository->save($theirs->replaceDepartments(['viandes']));

        // Then : la clé est (utilisateur, rayon), l'un ne déplace pas le rayon de l'autre
        $this->assertSame(['viandes'], $this->repository->findOneById('mine')->getDepartmentSlugs());
        $this->assertSame(['viandes'], $this->repository->findOneById('theirs')->getDepartmentSlugs());
    }

    public function testOwnerHasAtLeastStopsAtTheNthType(): void
    {
        // Given
        foreach ([1, 2, 3] as $number) {
            $this->repository->save(ShoppingListType::create("type-$number", self::USER, "Magasin $number", new DateTimeImmutable("2026-10-31 10:0$number:00")));
        }
        $this->repository->save(ShoppingListType::create('type-other', self::OTHER, 'Autre', new DateTimeImmutable()));

        // Then
        $this->assertTrue($this->repository->ownerHasAtLeast(self::USER, 3));
        $this->assertFalse($this->repository->ownerHasAtLeast(self::USER, 4));
        $this->assertTrue($this->repository->ownerHasAtLeast(self::OTHER, 1));
        $this->assertFalse($this->repository->ownerHasAtLeast('nobody', 1));
    }

    public function testOwnerHasAtLeastReadsASingleRowWhateverTheNumberOfTypes(): void
    {
        // Given
        foreach (range(1, 4) as $number) {
            $this->repository->save(ShoppingListType::create("type-$number", self::USER, "Magasin $number", new DateTimeImmutable()));
        }
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $this->repository->ownerHasAtLeast(self::USER, ShoppingListType::MAX_PER_OWNER);

        // Then : une requête, qui s'arrête après la ligne cherchée (jamais un COUNT)
        $this->assertSame(1, $counter->count());
        $this->assertStringNotContainsStringIgnoringCase('count(', $counter->statements()[0]);
    }

    public function testOwnerHasTypeNamedJudgesTheNormalizedNameOfTheUserAndSkipsTheTypeAskingForIt(): void
    {
        // Given
        $this->repository->save(ShoppingListType::create('type-1', self::USER, 'Marché', new DateTimeImmutable()));

        // Then
        $this->assertTrue($this->repository->ownerHasTypeNamed(self::USER, 'marche'));
        $this->assertFalse($this->repository->ownerHasTypeNamed(self::USER, 'marche', 'type-1'));
        $this->assertFalse($this->repository->ownerHasTypeNamed(self::OTHER, 'marche'));
        $this->assertFalse($this->repository->ownerHasTypeNamed(self::USER, 'boucherie'));
    }

    public function testDeletingATypeRemovesItAndItsAttachmentsOnly(): void
    {
        // Given
        $butcher = ShoppingListType::create('butcher', self::USER, 'Boucherie', new DateTimeImmutable())->replaceDepartments(['viandes']);
        $market = ShoppingListType::create('market', self::USER, 'Marché', new DateTimeImmutable())->replaceDepartments(['legumes']);
        $this->repository->save($butcher);
        $this->repository->save($market);

        // When
        $this->repository->delete($butcher);

        // Then
        $this->assertSame(0, $this->attachmentsOf('viandes'));
        $this->assertSame(['legumes'], $this->repository->findOneById('market')->getDepartmentSlugs());
        $this->expectExceptionObject(ShoppingListTypeException::notFound());
        $this->repository->findOneById('butcher');
    }

    public function testDeletingByOwnerRemovesAllHisTypesAndAttachmentsAndNobodyElses(): void
    {
        // Given
        $this->repository->save(ShoppingListType::create('mine', self::USER, 'Boucherie', new DateTimeImmutable())->replaceDepartments(['viandes']));
        $this->repository->save(ShoppingListType::create('mine-too', self::USER, 'Marché', new DateTimeImmutable())->replaceDepartments(['legumes']));
        $this->repository->save(ShoppingListType::create('theirs', self::OTHER, 'Boucherie', new DateTimeImmutable())->replaceDepartments(['viandes']));

        // When
        $this->repository->deleteByOwner(self::USER);

        // Then
        $connection = $this->entityManager->getConnection();
        $this->assertSame(['theirs'], $connection->fetchFirstColumn('SELECT id FROM shopping_list_type'));
        $this->assertSame([self::OTHER], $connection->fetchFirstColumn('SELECT owner_id FROM department_list_type'));
    }

    private function attachmentsOf(string $departmentSlug): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM department_list_type WHERE department_slug = ?', [$departmentSlug]);
    }
}
