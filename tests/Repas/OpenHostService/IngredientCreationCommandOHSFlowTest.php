<?php

namespace Repas\Tests\Repas\OpenHostService;

use Repas\Repas\Application\OpenHostService\IngredientCreationCommandOHS;
use Repas\Repas\Application\OpenHostService\IngredientPhraseCommandOHS;
use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Model\User;

/**
 * L'Open Host Service `IngredientCreationCommandOHS` câblé pour de vrai : bus transactionnel, `CreateIngredientHandler` et repositories sur la base
 * de test. Les rayons et unités sont ceux que le test écrit (aucune fixture globale).
 */
class IngredientCreationCommandOHSFlowTest extends DatabaseTestCase
{
    private IngredientCreationCommandOHS $creator;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creator = static::getContainer()->get(IngredientCreationCommandOHS::class);
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
        $departments = array_map(static fn($choice): string => $choice->getSlug(), $this->creator->departments());
        $units = array_map(static fn($choice): string => $choice->getSlug(), $this->creator->units());

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
        $created = $this->creator->create($user->getId(), 'ciboulette', $department->getSlug(), 'unite');
        $this->entityManager->clear();

        // Then
        $this->assertSame(['ciboulette', $department->getName()], [$created->name, $created->departmentName]);
        $ingredient = static::getContainer()->get(IngredientRepository::class)->findOneBySlug($created->slug);
        $this->assertSame($user->getId(), $ingredient->getCreatorId());
        $this->assertSame('Ciboulette', $ingredient->getName());
        $this->assertSame($department->getSlug(), $ingredient->getDepartment()->getSlug());
        $this->assertSame('unite', $ingredient->getDefaultPurchaseUnit()->getSlug());
        $this->assertSame('unite', $ingredient->getDefaultCookingUnit()->getSlug());
        $this->assertFalse($ingredient->isBase());
    }

    public function testTheSameIngredientTwiceIsRefusedAndTheSecondWritesNothing(): void
    {
        // Given
        $user = $this->savedUser();
        $department = $this->creator->departments()[0];
        $this->creator->create($user->getId(), 'ciboulette', $department->getSlug(), 'unite');
        $before = (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM ingredient');

        // Then
        $this->expectException(IngredientException::class);
        try {
            $this->creator->create($user->getId(), 'ciboulette', $department->getSlug(), 'kilo');
        } finally {
            $this->assertSame($before, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM ingredient'));
        }
    }

    public function testTheSameNameInAnotherDepartmentIsCreatedAndTheResolverThenAsksWhichOne(): void
    {
        // Given : « petits pois » créé par la voix dans un rayon, puis dans l'autre (ADR 0028, amendement du 2026-10-30)
        $user = $this->savedUser();
        [$first, $second] = $this->creator->departments();
        $a = $this->creator->create($user->getId(), 'petits pois', $first->getSlug(), 'unite');
        $b = $this->creator->create($user->getId(), 'petits pois', $second->getSlug(), 'unite');
        $this->entityManager->clear();

        // Then : deux ingrédients, deux identifiants, chacun dans son rayon
        $this->assertNotSame($a->slug, $b->slug);
        $repository = static::getContainer()->get(IngredientRepository::class);
        $this->assertSame([$first->getSlug(), $second->getSlug()], [$repository->findOneBySlug($a->slug)->getDepartment()->getSlug(), $repository->findOneBySlug($b->slug)->getDepartment()->getSlug()]);

        // When : il dit « des petits pois »
        $resolution = static::getContainer()->get(IngredientPhraseCommandOHS::class)->resolveOrSuggest($user->getId(), 'des petits pois');

        // Then : Alexa ne devine pas, elle départage par le rayon (les deux, chacun avec son rayon)
        $this->assertNull($resolution->ingredient);
        $this->assertSame([], $resolution->suggestions);
        $this->assertEqualsCanonicalizing([$a->slug, $b->slug], array_map(static fn($tie): string => $tie->slug, $resolution->ties));
        $this->assertEqualsCanonicalizing([$first->getSlug(), $second->getSlug()], array_map(static fn($tie): string => $tie->departmentSlug, $resolution->ties));

        // When : le même nom dans un rayon qui l'a déjà
        $before = (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM ingredient');
        try {
            $this->creator->create($user->getId(), 'Petits pois', $second->getSlug(), 'kilo');
            $this->fail('Le doublon dans le même rayon aurait dû être refusé.');
        } catch (IngredientException $exception) {
            // Then
            $this->assertSame(IngredientException::NAME_ALREADY_USED, $exception->getMessage());
            $this->assertSame($before, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM ingredient'));
        }
    }

    public function testTwoUsersCanEachCreateTheSameName(): void
    {
        // Given
        $department = $this->creator->departments()[0];

        // When
        $first = $this->creator->create($this->savedUser()->getId(), 'ciboulette', $department->getSlug(), 'unite');
        $second = $this->creator->create($this->savedUser()->getId(), 'ciboulette', $department->getSlug(), 'unite');

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
            $this->creator->create($user->getId(), 'ciboulette', $department->getSlug(), 'inconnue');
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
        $created = $this->creator->create($user->getId(), 'ciboulette', $department->getSlug(), 'unite');

        // Then : le créateur est l'utilisateur de l'appel
        $this->assertSame($user->getId(), static::getContainer()->get(IngredientRepository::class)->findOneBySlug($created->slug)->getCreatorId());
    }
}
