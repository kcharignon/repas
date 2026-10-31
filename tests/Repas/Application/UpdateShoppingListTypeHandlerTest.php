<?php

namespace Repas\Tests\Repas\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\UpdateShoppingListType\UpdateShoppingListTypeCommand;
use Repas\Repas\Application\Command\UpdateShoppingListType\UpdateShoppingListTypeHandler;
use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Exception\ShoppingListTypeException;
use Repas\Repas\Domain\Model\ShoppingListType;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\InMemoryRepository\DepartmentInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListTypeInMemoryRepository;

class UpdateShoppingListTypeHandlerTest extends TestCase
{
    private ShoppingListTypeInMemoryRepository $listTypeRepository;
    private UpdateShoppingListTypeHandler $handler;

    protected function setUp(): void
    {
        $this->listTypeRepository = new ShoppingListTypeInMemoryRepository([
            self::type('butcher', 'user-1', 'Boucherie', ['viande']),
            self::type('market', 'user-1', 'Marché', ['divers']),
            self::type('other', 'user-2', 'Boucherie', ['viande']),
        ]);
        $this->handler = new UpdateShoppingListTypeHandler(
            $this->listTypeRepository,
            new DepartmentInMemoryRepository([
                new DepartmentBuilder()->isMeat()->build(),
                new DepartmentBuilder()->isMiscellaneous()->build(),
                new DepartmentBuilder()->isCheese()->build(),
            ]),
        );
    }

    public function testReplacesTheNameAndTheCompleteSetOfDepartments(): void
    {
        // When
        ($this->handler)(new UpdateShoppingListTypeCommand('butcher', 'charcuterie', ['fromage', 'viande'], 'user-1'));

        // Then : le nom en majuscule, ses rayons, et rien d'autre ne bouge
        $butcher = $this->listTypeRepository->findOneById('butcher');
        $this->assertSame(['Charcuterie', ['fromage', 'viande']], [$butcher->getName(), $butcher->getDepartmentSlugs()]);
        $this->assertSame(['divers'], $this->listTypeRepository->findOneById('market')->getDepartmentSlugs());
        $this->assertSame(['viande'], $this->listTypeRepository->findOneById('other')->getDepartmentSlugs());
    }

    public function testADepartmentTheTypeNoLongerListsIsDetached(): void
    {
        // When
        ($this->handler)(new UpdateShoppingListTypeCommand('butcher', 'Boucherie', [], 'user-1'));

        // Then
        $this->assertSame([], $this->listTypeRepository->findOneById('butcher')->getDepartmentSlugs());
    }

    public function testADepartmentAlreadyTakenByAnotherTypeOfTheUserIsMovedNotDuplicated(): void
    {
        // When : « Marché » prend le rayon « viande », qui était à « Boucherie »
        ($this->handler)(new UpdateShoppingListTypeCommand('market', 'Marché', ['divers', 'viande'], 'user-1'));

        // Then : un rayon est dans un seul type à la fois ; le type d'un AUTRE utilisateur garde le sien
        $this->assertSame(['divers', 'viande'], $this->listTypeRepository->findOneById('market')->getDepartmentSlugs());
        $this->assertSame([], $this->listTypeRepository->findOneById('butcher')->getDepartmentSlugs());
        $this->assertSame(['viande'], $this->listTypeRepository->findOneById('other')->getDepartmentSlugs());
    }

    public function testADuplicatedDepartmentInTheBodyIsKeptOnce(): void
    {
        // When
        ($this->handler)(new UpdateShoppingListTypeCommand('butcher', 'Boucherie', ['fromage', 'fromage'], 'user-1'));

        // Then
        $this->assertSame(['fromage'], $this->listTypeRepository->findOneById('butcher')->getDepartmentSlugs());
    }

    public function testAnUnknownDepartmentIsNotFoundAndNothingIsWritten(): void
    {
        // Then
        $this->expectExceptionObject(DepartmentException::notFound());

        // When
        try {
            ($this->handler)(new UpdateShoppingListTypeCommand('butcher', 'Charcuterie', ['fromage', 'inconnu'], 'user-1'));
        } finally {
            $butcher = $this->listTypeRepository->findOneById('butcher');
            $this->assertSame(['Boucherie', ['viande']], [$butcher->getName(), $butcher->getDepartmentSlugs()]);
        }
    }

    public function testRefusesANameAlreadyUsedByAnotherTypeOfTheAccountAndWritesNothing(): void
    {
        // Then
        $this->expectExceptionObject(ShoppingListTypeException::nameAlreadyUsed());

        // When
        try {
            ($this->handler)(new UpdateShoppingListTypeCommand('butcher', 'marche', ['fromage'], 'user-1'));
        } finally {
            $butcher = $this->listTypeRepository->findOneById('butcher');
            $this->assertSame(['Boucherie', ['viande']], [$butcher->getName(), $butcher->getDepartmentSlugs()]);
        }
    }

    public function testKeepingItsOwnNameIsNeverRefusedWhateverTheCase(): void
    {
        // When : le même nom, écrit autrement, avec d'autres rayons
        ($this->handler)(new UpdateShoppingListTypeCommand('butcher', 'BOUCHERIE', ['fromage'], 'user-1'));

        // Then
        $butcher = $this->listTypeRepository->findOneById('butcher');
        $this->assertSame(['BOUCHERIE', ['fromage']], [$butcher->getName(), $butcher->getDepartmentSlugs()]);
    }

    public function testTheTypeOfAnotherUserIsUnknown(): void
    {
        // Then
        $this->expectExceptionObject(ShoppingListTypeException::notFound());

        // When
        try {
            ($this->handler)(new UpdateShoppingListTypeCommand('butcher', 'Charcuterie', ['fromage'], 'user-2'));
        } finally {
            $this->assertSame('Boucherie', $this->listTypeRepository->findOneById('butcher')->getName());
        }
    }

    public function testAnUnknownTypeIsNotFound(): void
    {
        // Then
        $this->expectExceptionObject(ShoppingListTypeException::notFound());

        // When
        ($this->handler)(new UpdateShoppingListTypeCommand('inconnu', 'Charcuterie', [], 'user-1'));
    }

    public function testRefusesAnInvalidName(): void
    {
        // Then
        $this->expectExceptionObject(ShoppingListTypeException::invalidName());

        // When
        ($this->handler)(new UpdateShoppingListTypeCommand('butcher', '   ', [], 'user-1'));
    }

    /**
     * @param list<string> $departments
     */
    private static function type(string $id, string $owner, string $name, array $departments): ShoppingListType
    {
        return ShoppingListType::load(['id' => $id, 'owner_id' => $owner, 'name' => $name, 'slugged_name' => mb_strtolower(str_replace('é', 'e', $name)), 'created_at' => new DateTimeImmutable('2026-10-31 10:00:00'), 'department_slugs' => $departments]);
    }
}
