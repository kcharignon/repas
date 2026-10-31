<?php

namespace Repas\Tests\Repas\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\CreateShoppingListType\CreateShoppingListTypeCommand;
use Repas\Repas\Application\Command\CreateShoppingListType\CreateShoppingListTypeHandler;
use Repas\Repas\Domain\Exception\ShoppingListTypeException;
use Repas\Repas\Domain\Model\ShoppingListType;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListTypeInMemoryRepository;

class CreateShoppingListTypeHandlerTest extends TestCase
{
    private ShoppingListTypeInMemoryRepository $listTypeRepository;
    private CreateShoppingListTypeHandler $handler;

    protected function setUp(): void
    {
        $this->listTypeRepository = new ShoppingListTypeInMemoryRepository();
        $this->handler = new CreateShoppingListTypeHandler($this->listTypeRepository, new FrozenClock(new DateTimeImmutable('2026-10-31 10:00:00')));
    }

    public function testCreatesATypeForTheUserWithoutAnyDepartment(): void
    {
        // When
        ($this->handler)(new CreateShoppingListTypeCommand('type-1', 'Boucherie', 'user-1'));

        // Then
        $type = $this->listTypeRepository->findOneById('type-1');
        $this->assertSame(['user-1', 'Boucherie', 'boucherie', []], [$type->getOwnerId(), $type->getName(), $type->getSluggedName(), $type->getDepartmentSlugs()]);
        $this->assertSame('2026-10-31 10:00:00', $type->getCreatedAt()->format('Y-m-d H:i:s'));
    }

    public function testTheNameStartsWithACapitalSilently(): void
    {
        // When
        ($this->handler)(new CreateShoppingListTypeCommand('type-1', 'marché', 'user-1'));

        // Then
        $this->assertSame('Marché', $this->listTypeRepository->findOneById('type-1')->getName());
    }

    public function testRefusesTheEleventhTypeOfAnAccountAndWritesNothing(): void
    {
        // Given : dix types déjà, et un autre compte qui n'en a aucun
        for ($i = 1; $i <= ShoppingListType::MAX_PER_OWNER; $i++) {
            ($this->handler)(new CreateShoppingListTypeCommand("type-$i", "Magasin $i", 'user-1'));
        }

        // When & Then
        try {
            ($this->handler)(new CreateShoppingListTypeCommand('type-11', 'Onzième', 'user-1'));
            $this->fail('Le onzième type aurait dû être refusé.');
        } catch (ShoppingListTypeException $exception) {
            $this->assertEquals(ShoppingListTypeException::limitReached(), $exception);
        }
        $this->assertCount(10, $this->listTypeRepository->all());
        // l'autre compte n'a aucun type : le plafond est celui de chaque compte
        ($this->handler)(new CreateShoppingListTypeCommand('type-other', 'Onzième', 'user-2'));
        $this->assertCount(11, $this->listTypeRepository->all());
    }

    public function testRefusesTwoTypesWithTheSameNormalizedNameOfTheSameAccountButNotOfAnotherOne(): void
    {
        // Given
        ($this->handler)(new CreateShoppingListTypeCommand('type-1', 'Marché', 'user-1'));

        // When : le même compte, le nom écrit autrement
        try {
            ($this->handler)(new CreateShoppingListTypeCommand('type-2', 'MARCHE', 'user-1'));
            $this->fail('Le doublon aurait dû être refusé.');
        } catch (ShoppingListTypeException $exception) {
            $this->assertEquals(ShoppingListTypeException::nameAlreadyUsed(), $exception);
        }
        ($this->handler)(new CreateShoppingListTypeCommand('type-3', 'Marché', 'user-2'));

        // Then
        $this->assertSame(['type-1', 'type-3'], array_map(static fn(ShoppingListType $type): string => $type->getId(), $this->listTypeRepository->all()));
    }

    public function testRefusesAnInvalidNameAndWritesNothing(): void
    {
        // Then
        $this->expectExceptionObject(ShoppingListTypeException::invalidName());

        // When
        try {
            ($this->handler)(new CreateShoppingListTypeCommand('type-1', str_repeat('a', 41), 'user-1'));
        } finally {
            $this->assertSame([], $this->listTypeRepository->all());
        }
    }
}
