<?php

namespace Repas\Tests\Repas\OutputAdapter;


use DateTimeImmutable;
use Repas\Repas\Application\Loader\SpokenPhraseMemory;
use Repas\Repas\Domain\Interface\SpokenPhraseChoiceRepository;
use Repas\Tests\Helper\DatabaseTestCase;

/**
 * La table `spoken_phrase_choice` et sa lecture sur la vraie base (ADR 0029 décision 11, VOX-303). Aucune clé étrangère : les utilisateurs
 * et les ingrédients n'ont pas besoin d'exister, chaque test écrit ses propres lignes.
 */
class SpokenPhraseChoiceRepositoryTest extends DatabaseTestCase
{
    private const string USER = 'user-id';
    private const string OTHER = 'other-id';

    private SpokenPhraseChoiceRepository $repository;
    private SpokenPhraseMemory $memory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = static::getContainer()->get(SpokenPhraseChoiceRepository::class);
        $this->memory = static::getContainer()->get(SpokenPhraseMemory::class);
    }

    /**
     * @return list<array{user_id: string, phrase: string, ingredient_slug: string, times: int, updated_at: string}>
     */
    private function rows(): array
    {
        return array_map(
            static fn(array $row): array => [...$row, 'times' => (int) $row['times']],
            $this->entityManager->getConnection()->fetchAllAssociative('SELECT user_id, phrase, ingredient_slug, times, updated_at FROM spoken_phrase_choice ORDER BY user_id, phrase'),
        );
    }

    public function testAChoiceIsWrittenAndReadBack(): void
    {
        // When
        $this->repository->remember(self::USER, 'ciboulette', 'persil-user-id', new DateTimeImmutable('2026-10-25 10:00:00'));

        // Then
        $this->assertSame([['user_id' => self::USER, 'phrase' => 'ciboulette', 'ingredient_slug' => 'persil-user-id', 'times' => 1, 'updated_at' => '2026-10-25 10:00:00']], $this->rows());
        $this->assertSame('persil-user-id', $this->memory->rememberedSlug(self::USER, 'ciboulette'));
    }

    public function testTheSameChoiceAgainCountsAndAnotherOneReplacesAndRestartsTheCount(): void
    {
        // When
        $this->repository->remember(self::USER, 'ciboulette', 'persil-user-id', new DateTimeImmutable('2026-10-25 10:00:00'));
        $this->repository->remember(self::USER, 'ciboulette', 'persil-user-id', new DateTimeImmutable('2026-10-25 11:00:00'));
        $afterTwice = $this->rows();
        $this->repository->remember(self::USER, 'ciboulette', 'basilic-user-id', new DateTimeImmutable('2026-10-25 12:00:00'));

        // Then
        $this->assertSame([2, '2026-10-25 11:00:00'], [$afterTwice[0]['times'], $afterTwice[0]['updated_at']]);
        $this->assertSame([['user_id' => self::USER, 'phrase' => 'ciboulette', 'ingredient_slug' => 'basilic-user-id', 'times' => 1, 'updated_at' => '2026-10-25 12:00:00']], $this->rows());
        $this->assertSame('basilic-user-id', $this->memory->rememberedSlug(self::USER, 'ciboulette'));
    }

    public function testTwoUsersHaveTheirOwnMemoryForTheSamePhrase(): void
    {
        // When
        $this->repository->remember(self::USER, 'ciboulette', 'persil-user-id', new DateTimeImmutable());
        $this->repository->remember(self::OTHER, 'ciboulette', 'persil-other-id', new DateTimeImmutable());

        // Then
        $this->assertCount(2, $this->rows());
        $this->assertSame('persil-user-id', $this->memory->rememberedSlug(self::USER, 'ciboulette'));
        $this->assertSame('persil-other-id', $this->memory->rememberedSlug(self::OTHER, 'ciboulette'));
        $this->assertNull($this->memory->rememberedSlug('nobody', 'ciboulette'));
        $this->assertNull($this->memory->rememberedSlug(self::USER, 'autre'));
    }

    public function testDeletingAUserKeepsTheMemoryOfTheOthers(): void
    {
        // Given
        $this->repository->remember(self::USER, 'ciboulette', 'persil-user-id', new DateTimeImmutable());
        $this->repository->remember(self::USER, 'persil', 'persil-user-id', new DateTimeImmutable());
        $this->repository->remember(self::OTHER, 'ciboulette', 'persil-other-id', new DateTimeImmutable());

        // When
        $this->repository->deleteByUser(self::USER);

        // Then
        $this->assertSame([[self::OTHER, 'ciboulette']], array_map(static fn(array $row): array => [$row['user_id'], $row['phrase']], $this->rows()));
    }

    public function testDeletingAnIngredientForgetsItsPhrasesForItsOwnerOnly(): void
    {
        // Given
        $this->repository->remember(self::USER, 'ciboulette', 'persil-user-id', new DateTimeImmutable());
        $this->repository->remember(self::USER, 'persil', 'persil-user-id', new DateTimeImmutable());
        $this->repository->remember(self::USER, 'basilic', 'basilic-user-id', new DateTimeImmutable());
        $this->repository->remember(self::OTHER, 'ciboulette', 'persil-user-id', new DateTimeImmutable());

        // When
        $this->repository->deleteByIngredientSlug(self::USER, 'persil-user-id');

        // Then
        $this->assertSame([[self::OTHER, 'ciboulette'], [self::USER, 'basilic']], array_map(static fn(array $row): array => [$row['user_id'], $row['phrase']], $this->rows()));
    }

    public function testMergingRepointsThePhrasesOfTheAbsorbedIngredientForItsOwnerOnly(): void
    {
        // Given
        $this->repository->remember(self::USER, 'ciboulette', 'tomates-user-id', new DateTimeImmutable());
        $this->repository->remember(self::USER, 'tomate', 'tomate-user-id', new DateTimeImmutable());
        $this->repository->remember(self::OTHER, 'ciboulette', 'tomates-user-id', new DateTimeImmutable());

        // When
        $this->repository->repoint(self::USER, 'tomates-user-id', 'tomate-user-id');

        // Then
        $this->assertSame(
            [[self::OTHER, 'ciboulette', 'tomates-user-id'], [self::USER, 'ciboulette', 'tomate-user-id'], [self::USER, 'tomate', 'tomate-user-id']],
            array_map(static fn(array $row): array => [$row['user_id'], $row['phrase'], $row['ingredient_slug']], $this->rows()),
        );
    }

    public function testTheKeyOfTheLongestPhraseFitsTheColumn(): void
    {
        // Given : cent caractères, la borne de la colonne
        $key = str_repeat('a', 100);

        // When
        $this->repository->remember(self::USER, $key, 'persil-user-id', new DateTimeImmutable());

        // Then
        $this->assertSame('persil-user-id', $this->memory->rememberedSlug(self::USER, $key));
    }
}
