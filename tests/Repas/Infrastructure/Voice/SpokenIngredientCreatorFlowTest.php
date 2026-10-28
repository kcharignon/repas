<?php

namespace Repas\Tests\Repas\Infrastructure\Voice;

use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Shared\Application\Interface\SpokenIngredientCreator;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Model\User;

/**
 * Le port `SpokenIngredientCreator` câblé pour de vrai : adaptateur, bus transactionnel, `CreateIngredientHandler` et repositories sur la base
 * de test. Les rayons et unités sont ceux que le test écrit (aucune fixture globale).
 */
class SpokenIngredientCreatorFlowTest extends DatabaseTestCase
{
    private SpokenIngredientCreator $creator;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creator = static::getContainer()->get(SpokenIngredientCreator::class);
        $this->persister = new DataPersister(static::getContainer());
        $this->persister->saveDepartment(new DepartmentBuilder()->isCereal()->build());
        $this->persister->saveDepartment(new DepartmentBuilder()->isMeat()->build());
        $this->persister->saveUnit(new UnitBuilder()->isUnite()->build());
        $this->persister->saveUnit(new UnitBuilder()->isKilo()->build());
    }

    private function savedUser(): User
    {
        $user = new UserBuilder()->build();
        $this->persister->saveUser($user);

        return $user;
    }

    public function testItListsTheDepartmentsAndTheUnitsOfTheCatalogueSortedBySlug(): void
    {
        // When
        $departments = array_map(static fn($choice): string => $choice->slug, $this->creator->departments());
        $units = array_map(static fn($choice): string => $choice->slug, $this->creator->units());

        // Then
        $sortedDepartments = $departments;
        sort($sortedDepartments);
        $sortedUnits = $units;
        sort($sortedUnits);
        $this->assertCount(2, $departments);
        $this->assertSame($sortedDepartments, $departments);
        $this->assertSame(['kilo', 'unite'], $sortedUnits);
        $this->assertSame($sortedUnits, $units);
    }

    public function testItCreatesTheIngredientForTheUserWithOneUnitForPurchaseAndCooking(): void
    {
        // Given
        $user = $this->savedUser();
        $department = $this->creator->departments()[0];

        // When
        $created = $this->creator->create($user->getId(), 'ciboulette', $department->slug, 'unite');
        $this->entityManager->clear();

        // Then
        $this->assertSame(['ciboulette', $department->name], [$created->name, $created->departmentName]);
        $ingredient = static::getContainer()->get(IngredientRepository::class)->findOneBySlug($created->slug);
        $this->assertSame($user->getId(), $ingredient->getCreatorId());
        $this->assertSame('Ciboulette', $ingredient->getName());
        $this->assertSame($department->slug, $ingredient->getDepartment()->getSlug());
        $this->assertSame('unite', $ingredient->getDefaultPurchaseUnit()->getSlug());
        $this->assertSame('unite', $ingredient->getDefaultCookingUnit()->getSlug());
        $this->assertFalse($ingredient->isBase());
    }

    public function testTheSameIngredientTwiceIsRefusedAndTheSecondWritesNothing(): void
    {
        // Given
        $user = $this->savedUser();
        $department = $this->creator->departments()[0];
        $this->creator->create($user->getId(), 'ciboulette', $department->slug, 'unite');
        $before = (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM ingredient');

        // Then
        $this->expectException(IngredientException::class);
        try {
            $this->creator->create($user->getId(), 'ciboulette', $department->slug, 'kilo');
        } finally {
            $this->assertSame($before, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM ingredient'));
        }
    }

    public function testTwoUsersCanEachCreateTheSameName(): void
    {
        // Given
        $department = $this->creator->departments()[0];

        // When
        $first = $this->creator->create($this->savedUser()->getId(), 'ciboulette', $department->slug, 'unite');
        $second = $this->creator->create($this->savedUser()->getId(), 'ciboulette', $department->slug, 'unite');

        // Then
        $this->assertNotSame($first->slug, $second->slug);
    }

    public function testAnUnknownDepartmentIsRefusedAndNothingIsWritten(): void
    {
        // Given
        $user = $this->savedUser();
        $before = (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM ingredient');

        // Then
        $this->expectException(DepartmentException::class);
        try {
            $this->creator->create($user->getId(), 'ciboulette', 'inconnu', 'unite');
        } finally {
            $this->assertSame($before, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM ingredient'));
        }
    }

    public function testAnUnknownUnitIsRefusedAndNothingIsWritten(): void
    {
        // Given
        $user = $this->savedUser();
        $department = $this->creator->departments()[0];
        $before = (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM ingredient');

        // Then
        $this->expectException(UnitException::class);
        try {
            $this->creator->create($user->getId(), 'ciboulette', $department->slug, 'inconnue');
        } finally {
            $this->assertSame($before, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM ingredient'));
        }
    }

    public function testTheCreatorOfTheCreatedIngredientIsTheUserOfTheCall(): void
    {
        // Given
        $user = $this->savedUser();
        $other = $this->savedUser();
        $this->persister->saveIngredient(new IngredientBuilder()->isBread()->withCreator($other, true)->build());
        $department = $this->creator->departments()[0];

        // When
        $created = $this->creator->create($user->getId(), 'ciboulette', $department->slug, 'unite');

        // Then : le créateur est l'utilisateur de l'appel
        $this->assertSame($user->getId(), static::getContainer()->get(IngredientRepository::class)->findOneBySlug($created->slug)->getCreatorId());
    }
}
