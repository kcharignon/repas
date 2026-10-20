<?php

namespace Repas\Tests\Repas\Domain;


use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\IngredientAliasException;
use Repas\Repas\Domain\Model\AliasKind;
use Repas\Repas\Domain\Model\IngredientAlias;

class IngredientAliasTest extends TestCase
{
    public function testAnAliasIsAnUserASlugAndTheIngredientItDesignates(): void
    {
        // When
        $alias = IngredientAlias::create('user-id', 'beurre', 'beurreuser-id', AliasKind::ADOPTION, 'migration-2026-10-21', new DateTimeImmutable('2026-10-21 03:00:00'));

        // Then
        $this->assertSame('user-id|beurre', $alias->getId());
        $this->assertSame(['user-id', 'beurre', 'beurreuser-id', AliasKind::ADOPTION, 'migration-2026-10-21'], [
            $alias->getUserId(), $alias->getAliasSlug(), $alias->getIngredientSlug(), $alias->getKind(), $alias->getSource(),
        ]);
        $this->assertTrue($alias->isAdoption());
        $this->assertEquals(new DateTimeImmutable('2026-10-21 03:00:00'), $alias->getCreatedAt());
    }

    public function testAMergeAliasIsNotAnAdoption(): void
    {
        // When
        $alias = IngredientAlias::create('user-id', 'tomate-2user-id', 'tomateuser-id', AliasKind::MERGE, 'merge', new DateTimeImmutable());

        // Then
        $this->assertFalse($alias->isAdoption());
    }

    public function testTheSameUserAndSlugIsTheSameAlias(): void
    {
        // Given
        $now = new DateTimeImmutable();
        $first = IngredientAlias::create('user-id', 'beurre', 'beurreuser-id', AliasKind::ADOPTION, 'first-use', $now);
        $sameKey = IngredientAlias::create('user-id', 'beurre', 'autre', AliasKind::MERGE, 'merge', $now);
        $otherUser = IngredientAlias::create('other-id', 'beurre', 'beurreuser-id', AliasKind::ADOPTION, 'first-use', $now);

        // Then
        $this->assertTrue($first->isEqual($sameKey));
        $this->assertFalse($first->isEqual($otherUser));
    }

    public function testTheSourceHoldsInTheColumn(): void
    {
        // When
        $alias = IngredientAlias::create('user-id', 'beurre', 'beurreuser-id', AliasKind::ADOPTION, str_repeat('s', IngredientAlias::MAX_SOURCE_LENGTH), new DateTimeImmutable());

        // Then
        $this->assertSame(IngredientAlias::MAX_SOURCE_LENGTH, mb_strlen($alias->getSource()));
    }

    #[DataProvider('invalidSources')]
    public function testAnEmptyOrTooLongSourceIsRefused(string $source): void
    {
        // Then
        $this->expectExceptionObject(IngredientAliasException::invalidSource($source));

        // When
        IngredientAlias::create('user-id', 'beurre', 'beurreuser-id', AliasKind::ADOPTION, $source, new DateTimeImmutable());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSources(): iterable
    {
        yield 'vide' => [''];
        yield 'trop longue' => [str_repeat('s', IngredientAlias::MAX_SOURCE_LENGTH + 1)];
    }

    public function testAnAliasCannotDesignateItself(): void
    {
        // Then
        $this->expectExceptionObject(IngredientAliasException::pointsToItself('beurre'));

        // When
        IngredientAlias::create('user-id', 'beurre', 'beurre', AliasKind::ADOPTION, 'first-use', new DateTimeImmutable());
    }
}
