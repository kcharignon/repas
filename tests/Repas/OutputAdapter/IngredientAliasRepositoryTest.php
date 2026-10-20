<?php

namespace Repas\Tests\Repas\OutputAdapter;


use DateTimeImmutable;
use Repas\Repas\Domain\Interface\IngredientAliasRepository;
use Repas\Repas\Domain\Model\AliasKind;
use Repas\Repas\Domain\Model\IngredientAlias;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\SqlBudget\QueryCounter;

/**
 * La table `ingredient_alias` sur la vraie base (ADR 0028). Aucune clé étrangère : les utilisateurs et les ingrédients désignés
 * n'ont pas besoin d'exister, chaque test écrit ses propres lignes.
 */
class IngredientAliasRepositoryTest extends DatabaseTestCase
{
    private const string USER = 'user-id';
    private const string OTHER = 'other-id';

    private IngredientAliasRepository $aliasRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aliasRepository = static::getContainer()->get(IngredientAliasRepository::class);
    }

    public function testSaveThenFindGivesTheSameAlias(): void
    {
        // Given
        $alias = $this->alias(self::USER, 'tomate', 'tomateuser-id', AliasKind::ADOPTION, 'migration-2026-10-21');

        // When
        $written = $this->aliasRepository->save($alias);

        // Then
        $this->assertTrue($written);
        $actual = $this->aliasRepository->find(self::USER, 'tomate');
        $this->assertNotNull($actual);
        $this->assertTrue($alias->isEqual($actual));
        $this->assertSame(['tomateuser-id', AliasKind::ADOPTION, 'migration-2026-10-21'], [$actual->getIngredientSlug(), $actual->getKind(), $actual->getSource()]);
        $this->assertEquals($alias->getCreatedAt(), $actual->getCreatedAt());
    }

    public function testAMergeAliasKeepsItsKind(): void
    {
        // When
        $this->aliasRepository->save($this->alias(self::USER, 'tomate-2user-id', 'tomateuser-id', AliasKind::MERGE, 'merge'));

        // Then
        $this->assertSame(AliasKind::MERGE, $this->aliasRepository->find(self::USER, 'tomate-2user-id')->getKind());
    }

    public function testFindIsPerUserAndAnUnknownAliasIsNull(): void
    {
        // Given
        $this->aliasRepository->save($this->alias(self::USER, 'tomate', 'tomateuser-id'));

        // Then
        $this->assertNull($this->aliasRepository->find(self::OTHER, 'tomate'));
        $this->assertNull($this->aliasRepository->find(self::USER, 'carotte'));
    }

    public function testTheFirstAliasOfAKeyIsNeverReplaced(): void
    {
        // Given : une adoption simultanée du même commun écrit deux fois la même clé
        $this->assertTrue($this->aliasRepository->save($this->alias(self::USER, 'tomate', 'tomateuser-id', AliasKind::ADOPTION, 'first-use')));

        // When
        $written = $this->aliasRepository->save($this->alias(self::USER, 'tomate', 'tomate-2user-id', AliasKind::ADOPTION, 'copy'));

        // Then : `ON CONFLICT DO NOTHING`, sans erreur, et le premier reste
        $this->assertFalse($written);
        $kept = $this->aliasRepository->find(self::USER, 'tomate');
        $this->assertSame(['tomateuser-id', 'first-use'], [$kept->getIngredientSlug(), $kept->getSource()]);
        $this->assertSame(1, $this->rowCount('ingredient_alias', 'user_id', self::USER));
    }

    public function testTheSameSlugCanBeAnAliasForTwoUsers(): void
    {
        // When
        $this->assertTrue($this->aliasRepository->save($this->alias(self::USER, 'tomate', 'tomateuser-id')));
        $this->assertTrue($this->aliasRepository->save($this->alias(self::OTHER, 'tomate', 'tomateother-id')));

        // Then
        $this->assertSame('tomateuser-id', $this->aliasRepository->find(self::USER, 'tomate')->getIngredientSlug());
        $this->assertSame('tomateother-id', $this->aliasRepository->find(self::OTHER, 'tomate')->getIngredientSlug());
    }

    public function testResolveGivesTheIngredientOfTheUserForEachKnownSlugInOneQuery(): void
    {
        // Given
        $this->aliasRepository->save($this->alias(self::USER, 'tomate', 'tomateuser-id'));
        $this->aliasRepository->save($this->alias(self::USER, 'carotte', 'carotteuser-id'));
        $this->aliasRepository->save($this->alias(self::USER, 'oignon-2user-id', 'oignonuser-id', AliasKind::MERGE, 'merge'));
        $this->aliasRepository->save($this->alias(self::OTHER, 'tomate', 'tomateother-id'));
        $this->aliasRepository->save($this->alias(self::OTHER, 'poireau', 'poireauother-id'));
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $resolved = $this->aliasRepository->resolve(self::USER, ['tomate', 'poireau', 'oignon-2user-id', 'inconnu', 'tomate']);

        // Then : les slugs sans alias pour cet utilisateur sont absents, ceux d'un autre aussi
        $this->assertEquals(['tomate' => 'tomateuser-id', 'oignon-2user-id' => 'oignonuser-id'], $resolved);
        $this->assertSame(['SELECT ingredient_alias' => 1], $counter->byTable());
    }

    public function testResolveOfNothingCostsNothing(): void
    {
        // Given
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // Then
        $this->assertSame([], $this->aliasRepository->resolve(self::USER, []));
        $this->assertSame(0, $counter->count());
    }

    public function testAUserIsAnAdopterWhenHeHasAtLeastOneAdoptionAlias(): void
    {
        // Given
        $this->aliasRepository->save($this->alias(self::USER, 'tomate', 'tomateuser-id', AliasKind::ADOPTION));
        $this->aliasRepository->save($this->alias(self::OTHER, 'tomate-2other-id', 'tomateother-id', AliasKind::MERGE, 'merge'));

        // Then : un alias de fusion seul ne fait pas un adoptant, un utilisateur sans alias non plus
        $this->assertTrue($this->aliasRepository->isAdopter(self::USER));
        $this->assertFalse($this->aliasRepository->isAdopter(self::OTHER));
        $this->assertFalse($this->aliasRepository->isAdopter('nobody-id'));
    }

    public function testDeleteByUserErasesOnlyHisAliases(): void
    {
        // Given
        $this->aliasRepository->save($this->alias(self::USER, 'tomate', 'tomateuser-id'));
        $this->aliasRepository->save($this->alias(self::USER, 'carotte', 'carotteuser-id'));
        $this->aliasRepository->save($this->alias(self::OTHER, 'tomate', 'tomateother-id'));

        // When
        $this->aliasRepository->deleteByUser(self::USER);

        // Then
        $this->assertSame(0, $this->rowCount('ingredient_alias', 'user_id', self::USER));
        $this->assertSame(1, $this->rowCount('ingredient_alias', 'user_id', self::OTHER));
        $this->assertFalse($this->aliasRepository->isAdopter(self::USER));
    }

    public function testDeleteByAliasSlugErasesOnlyThatKeyOfThatUser(): void
    {
        // Given
        $this->aliasRepository->save($this->alias(self::USER, 'tomate', 'tomateuser-id'));
        $this->aliasRepository->save($this->alias(self::USER, 'carotte', 'carotteuser-id'));
        $this->aliasRepository->save($this->alias(self::OTHER, 'tomate', 'tomateother-id'));

        // When
        $this->aliasRepository->deleteByAliasSlug(self::USER, 'tomate');

        // Then
        $this->assertNull($this->aliasRepository->find(self::USER, 'tomate'));
        $this->assertNotNull($this->aliasRepository->find(self::USER, 'carotte'));
        $this->assertNotNull($this->aliasRepository->find(self::OTHER, 'tomate'));
    }

    public function testDeleteByIngredientSlugErasesEveryAliasThatDesignatesItForThatUser(): void
    {
        // Given : deux alias (une adoption, une fusion) désignent le même ingrédient
        $this->aliasRepository->save($this->alias(self::USER, 'tomate', 'tomateuser-id', AliasKind::ADOPTION));
        $this->aliasRepository->save($this->alias(self::USER, 'tomate-2user-id', 'tomateuser-id', AliasKind::MERGE, 'merge'));
        $this->aliasRepository->save($this->alias(self::USER, 'carotte', 'carotteuser-id'));
        $this->aliasRepository->save($this->alias(self::OTHER, 'tomate', 'tomateuser-id'));

        // When
        $this->aliasRepository->deleteByIngredientSlug(self::USER, 'tomateuser-id');

        // Then
        $this->assertSame(['carotte'], $this->aliasKeysOf(self::USER));
        $this->assertSame(['tomate'], $this->aliasKeysOf(self::OTHER));
    }

    public function testDeletingWhatDoesNotExistIsNotAnError(): void
    {
        // When
        $this->aliasRepository->deleteByUser('nobody-id');
        $this->aliasRepository->deleteByAliasSlug('nobody-id', 'x');
        $this->aliasRepository->deleteByIngredientSlug('nobody-id', 'x');

        // Then
        $this->assertSame(0, $this->rowCount('ingredient_alias', 'user_id', 'nobody-id'));
    }

    private function alias(string $userId, string $aliasSlug, string $ingredientSlug, AliasKind $kind = AliasKind::ADOPTION, string $source = 'first-use'): IngredientAlias
    {
        return IngredientAlias::create($userId, $aliasSlug, $ingredientSlug, $kind, $source, new DateTimeImmutable('2026-10-21 03:04:05'));
    }

    /**
     * @return list<string>
     */
    private function aliasKeysOf(string $userId): array
    {
        $keys = $this->entityManager->getConnection()->fetchFirstColumn('SELECT alias_slug FROM ingredient_alias WHERE user_id = ? ORDER BY alias_slug', [$userId]);
        sort($keys);

        return $keys;
    }

    private function rowCount(string $table, string $column, string $value): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE %s = ?', $table, $column), [$value]);
    }
}
