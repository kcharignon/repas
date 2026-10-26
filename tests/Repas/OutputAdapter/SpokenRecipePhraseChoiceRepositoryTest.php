<?php

namespace Repas\Tests\Repas\OutputAdapter;


use DateTimeImmutable;
use Repas\Repas\Application\Loader\SpokenRecipePhraseMemory;
use Repas\Repas\Domain\Interface\SpokenRecipePhraseChoiceRepository;
use Repas\Tests\Helper\DatabaseTestCase;

/**
 * La table `spoken_recipe_phrase_choice` et sa lecture sur la vraie base (ADR 0029 décision 13, VOX-305). Aucune clé étrangère : les utilisateurs et
 * les recettes n'ont pas besoin d'exister, chaque test écrit ses propres lignes.
 */
class SpokenRecipePhraseChoiceRepositoryTest extends DatabaseTestCase
{
    private const string USER = 'user-id';
    private const string OTHER = 'other-id';

    private SpokenRecipePhraseChoiceRepository $repository;
    private SpokenRecipePhraseMemory $memory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = static::getContainer()->get(SpokenRecipePhraseChoiceRepository::class);
        $this->memory = static::getContainer()->get(SpokenRecipePhraseMemory::class);
    }

    /**
     * @return list<array{user_id: string, phrase: string, recipe_id: string, times: int, updated_at: string}>
     */
    private function rows(): array
    {
        return array_map(
            static fn(array $row): array => [...$row, 'times' => (int) $row['times']],
            $this->entityManager->getConnection()->fetchAllAssociative('SELECT user_id, phrase, recipe_id, times, updated_at FROM spoken_recipe_phrase_choice ORDER BY user_id, phrase'),
        );
    }

    public function testAChoiceIsWrittenAndReadBack(): void
    {
        // When
        $this->repository->remember(self::USER, 'gratin', 'recipe-1', new DateTimeImmutable('2026-10-26 10:00:00'));

        // Then
        $this->assertSame([['user_id' => self::USER, 'phrase' => 'gratin', 'recipe_id' => 'recipe-1', 'times' => 1, 'updated_at' => '2026-10-26 10:00:00']], $this->rows());
        $this->assertSame('recipe-1', $this->memory->rememberedId(self::USER, 'gratin'));
    }

    public function testTheSameChoiceAgainCountsAndAnotherOneReplacesAndRestartsTheCount(): void
    {
        // When
        $this->repository->remember(self::USER, 'gratin', 'recipe-1', new DateTimeImmutable('2026-10-26 10:00:00'));
        $this->repository->remember(self::USER, 'gratin', 'recipe-1', new DateTimeImmutable('2026-10-26 11:00:00'));
        $afterTwice = $this->rows();
        $this->repository->remember(self::USER, 'gratin', 'recipe-2', new DateTimeImmutable('2026-10-26 12:00:00'));

        // Then
        $this->assertSame([2, '2026-10-26 11:00:00'], [$afterTwice[0]['times'], $afterTwice[0]['updated_at']]);
        $this->assertSame([['user_id' => self::USER, 'phrase' => 'gratin', 'recipe_id' => 'recipe-2', 'times' => 1, 'updated_at' => '2026-10-26 12:00:00']], $this->rows());
        $this->assertSame('recipe-2', $this->memory->rememberedId(self::USER, 'gratin'));
    }

    public function testTwoUsersHaveTheirOwnMemoryForTheSamePhrase(): void
    {
        // When
        $this->repository->remember(self::USER, 'gratin', 'recipe-1', new DateTimeImmutable());
        $this->repository->remember(self::OTHER, 'gratin', 'recipe-9', new DateTimeImmutable());

        // Then
        $this->assertCount(2, $this->rows());
        $this->assertSame('recipe-1', $this->memory->rememberedId(self::USER, 'gratin'));
        $this->assertSame('recipe-9', $this->memory->rememberedId(self::OTHER, 'gratin'));
        $this->assertNull($this->memory->rememberedId('nobody', 'gratin'));
        $this->assertNull($this->memory->rememberedId(self::USER, 'autre'));
    }

    public function testTheMemoryOfTheProductsIsAnotherTableAndNeverMixed(): void
    {
        // When : la même phrase retenue pour une recette
        $this->repository->remember(self::USER, 'oeuf', 'recipe-1', new DateTimeImmutable());

        // Then : la mémoire des produits n'en sait rien
        $this->assertNull(static::getContainer()->get(\Repas\Repas\Application\Loader\SpokenPhraseMemory::class)->rememberedSlug(self::USER, 'oeuf'));
    }

    public function testDeletingAUserKeepsTheMemoryOfTheOthers(): void
    {
        // Given
        $this->repository->remember(self::USER, 'gratin', 'recipe-1', new DateTimeImmutable());
        $this->repository->remember(self::USER, 'hachis', 'recipe-2', new DateTimeImmutable());
        $this->repository->remember(self::OTHER, 'gratin', 'recipe-9', new DateTimeImmutable());

        // When
        $this->repository->deleteByUser(self::USER);

        // Then
        $this->assertSame([[self::OTHER, 'gratin']], array_map(static fn(array $row): array => [$row['user_id'], $row['phrase']], $this->rows()));
    }

    public function testDeletingARecipeForgetsItsPhrasesForItsOwnerOnly(): void
    {
        // Given
        $this->repository->remember(self::USER, 'gratin', 'recipe-1', new DateTimeImmutable());
        $this->repository->remember(self::USER, 'dauphinois', 'recipe-1', new DateTimeImmutable());
        $this->repository->remember(self::USER, 'hachis', 'recipe-2', new DateTimeImmutable());
        $this->repository->remember(self::OTHER, 'gratin', 'recipe-1', new DateTimeImmutable());

        // When
        $this->repository->deleteByRecipeId(self::USER, 'recipe-1');

        // Then
        $this->assertSame([[self::OTHER, 'gratin'], [self::USER, 'hachis']], array_map(static fn(array $row): array => [$row['user_id'], $row['phrase']], $this->rows()));
    }

    public function testTheKeyOfTheLongestPhraseFitsTheColumn(): void
    {
        // Given : cent caractères, la borne de la colonne
        $key = str_repeat('a', 100);

        // When
        $this->repository->remember(self::USER, $key, 'recipe-1', new DateTimeImmutable());

        // Then
        $this->assertSame('recipe-1', $this->memory->rememberedId(self::USER, $key));
    }
}
