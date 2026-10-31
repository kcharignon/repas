<?php

namespace Repas\Tests\Repas\Domain;


use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\ShoppingListTypeException;
use Repas\Repas\Domain\Model\ShoppingListType;

class ShoppingListTypeTest extends TestCase
{
    public function testANewTypeHasANameItsNormalizedNameAndNoDepartment(): void
    {
        // When
        $type = ShoppingListType::create('type-1', 'user-1', '  Marché  ', new DateTimeImmutable('2026-10-31 10:00:00'));

        // Then
        $this->assertSame('type-1', $type->getId());
        $this->assertSame('user-1', $type->getOwnerId());
        $this->assertSame('Marché', $type->getName());
        $this->assertSame('marche', $type->getSluggedName());
        $this->assertSame([], $type->getDepartmentSlugs());
        $this->assertSame('2026-10-31 10:00:00', $type->getCreatedAt()->format('Y-m-d H:i:s'));
    }

    public function testTwoNamesThatDifferOnlyByCaseAndAccentsAreTheSameOnceNormalized(): void
    {
        // When & Then
        $this->assertSame(
            ShoppingListType::create('a', 'user-1', 'Marché', new DateTimeImmutable())->getSluggedName(),
            ShoppingListType::create('b', 'user-1', 'MARCHE', new DateTimeImmutable())->getSluggedName(),
        );
    }

    #[DataProvider('invalidNames')]
    public function testRefusesAnInvalidName(string $name): void
    {
        // Then
        $this->expectExceptionObject(ShoppingListTypeException::invalidName());

        // When
        ShoppingListType::create('type-1', 'user-1', $name, new DateTimeImmutable());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'vide' => [''];
        yield 'des espaces' => ['   '];
        yield 'sans lettre ni chiffre' => ['???'];
        yield '41 caractères' => [str_repeat('é', 41)];
    }

    public function testAcceptsANameOfFortyCharacters(): void
    {
        // When
        $type = ShoppingListType::create('type-1', 'user-1', str_repeat('é', 40), new DateTimeImmutable());

        // Then
        $this->assertSame(40, mb_strlen($type->getName()));
    }

    public function testRenamingChangesTheNameAndTheNormalizedNameAndRefusesAnInvalidName(): void
    {
        // Given
        $type = ShoppingListType::create('type-1', 'user-1', 'Boucherie', new DateTimeImmutable());

        // When
        $type->rename('Charcuterie fine');

        // Then
        $this->assertSame(['Charcuterie fine', 'charcuterie-fine'], [$type->getName(), $type->getSluggedName()]);
        $this->expectExceptionObject(ShoppingListTypeException::invalidName());
        $type->rename('');
    }

    public function testAFailedRenameKeepsTheCurrentName(): void
    {
        // Given
        $type = ShoppingListType::create('type-1', 'user-1', 'Boucherie', new DateTimeImmutable());

        // When
        try {
            $type->rename(str_repeat('x', 41));
            $this->fail('Le nom aurait dû être refusé.');
        } catch (ShoppingListTypeException) {
        }

        // Then
        $this->assertSame(['Boucherie', 'boucherie'], [$type->getName(), $type->getSluggedName()]);
    }

    public function testTheDepartmentsAreReplacedAsAWholeWithoutDuplicatesAndSorted(): void
    {
        // Given
        $type = ShoppingListType::create('type-1', 'user-1', 'Boucherie', new DateTimeImmutable());

        // When
        $type->replaceDepartments(['viandes', 'poissons', 'viandes']);
        $first = $type->getDepartmentSlugs();
        $type->replaceDepartments(['epicerie']);

        // Then
        $this->assertSame(['poissons', 'viandes'], $first);
        $this->assertSame(['epicerie'], $type->getDepartmentSlugs());
    }

    public function testLoadingKeepsTheDepartmentsSortedWithoutDuplicates(): void
    {
        // When
        $type = ShoppingListType::load(['id' => 'type-1', 'owner_id' => 'user-1', 'name' => 'Boucherie', 'slugged_name' => 'boucherie', 'created_at' => new DateTimeImmutable(), 'department_slugs' => ['viandes', 'epicerie', 'viandes']]);

        // Then
        $this->assertSame(['epicerie', 'viandes'], $type->getDepartmentSlugs());
        $this->assertSame([], ShoppingListType::load(['id' => 'type-2', 'owner_id' => 'user-1', 'name' => 'Marché', 'slugged_name' => 'marche', 'created_at' => new DateTimeImmutable()])->getDepartmentSlugs());
    }

    public function testOnlyTheOwnerOwnsTheTypeAndAnotherUserGetsTheNotFoundRefusal(): void
    {
        // Given
        $type = ShoppingListType::create('type-1', 'user-1', 'Boucherie', new DateTimeImmutable());

        // Then
        $this->assertTrue($type->isOwnedBy('user-1'));
        $this->assertFalse($type->isOwnedBy('user-2'));
        $type->assertOwnedBy('user-1');
        $this->expectExceptionObject(ShoppingListTypeException::notFound());
        $type->assertOwnedBy('user-2');
    }

    public function testTheRefusalsHaveTheirStatusAndMessage(): void
    {
        // Then
        $this->assertSame([404, 'LIST_TYPE_NOT_FOUND'], [ShoppingListTypeException::notFound()->getCode(), ShoppingListTypeException::notFound()->getMessage()]);
        $this->assertSame([422, 'LIST_TYPE_LIMIT_REACHED'], [ShoppingListTypeException::limitReached()->getCode(), ShoppingListTypeException::limitReached()->getMessage()]);
        $this->assertSame([409, 'LIST_TYPE_NAME_ALREADY_USED'], [ShoppingListTypeException::nameAlreadyUsed()->getCode(), ShoppingListTypeException::nameAlreadyUsed()->getMessage()]);
        $this->assertSame([422, 'LIST_TYPE_NAME_INVALID'], [ShoppingListTypeException::invalidName()->getCode(), ShoppingListTypeException::invalidName()->getMessage()]);
    }
}
