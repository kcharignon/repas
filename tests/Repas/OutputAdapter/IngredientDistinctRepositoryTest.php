<?php

namespace Repas\Tests\Repas\OutputAdapter;


use DateTimeImmutable;
use Repas\Repas\Domain\Interface\IngredientDistinctRepository;
use Repas\Repas\Domain\Model\IngredientDistinctPair;
use Repas\Tests\Helper\DatabaseTestCase;

/**
 * La table `ingredient_distinct` sur la vraie base (ADR 0028, ING-006). Le schéma de test vient du mapping Doctrine : la contrainte `CHECK` de la migration
 * (premier slug avant le second) n'y est pas, elle est éprouvée sur la migration elle-même. Aucune clé étrangère : les utilisateurs et les ingrédients n'ont pas
 * besoin d'exister, chaque test écrit ses propres lignes.
 */
class IngredientDistinctRepositoryTest extends DatabaseTestCase
{
    private const string USER = 'user-id';
    private const string OTHER = 'other-id';

    private IngredientDistinctRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = static::getContainer()->get(IngredientDistinctRepository::class);
    }

    /**
     * @return list<array{user_id: string, first_slug: string, second_slug: string, created_at: string}>
     */
    private function rows(): array
    {
        return $this->entityManager->getConnection()->fetchAllAssociative('SELECT user_id, first_slug, second_slug, created_at FROM ingredient_distinct ORDER BY user_id, first_slug, second_slug');
    }

    public function testThePairIsWrittenOnceInTheOrderOfTheSlugsWhateverTheOrderOfTheCall(): void
    {
        // When
        $first = $this->repository->save(IngredientDistinctPair::create(self::USER, 'tomates', 'tomate', new DateTimeImmutable('2026-10-21 08:00:00')));
        $second = $this->repository->save(IngredientDistinctPair::create(self::USER, 'tomate', 'tomates', new DateTimeImmutable('2026-12-01 08:00:00')));

        // Then : une ligne, la première date est gardée, la repose ne dit rien de plus
        $this->assertSame([true, false], [$first, $second]);
        $this->assertSame([['user_id' => self::USER, 'first_slug' => 'tomate', 'second_slug' => 'tomates', 'created_at' => '2026-10-21 08:00:00']], $this->rows());
    }

    public function testThePairsOfTwoUsersAreTwoLines(): void
    {
        // When
        $this->repository->save(IngredientDistinctPair::create(self::USER, 'tomate', 'tomates', new DateTimeImmutable()));
        $this->repository->save(IngredientDistinctPair::create(self::OTHER, 'tomate', 'tomates', new DateTimeImmutable()));

        // Then
        $this->assertCount(2, $this->rows());
    }

    public function testTheOrderOfTheSlugsIsTheOneOfTheBytesNotOfTheLanguage(): void
    {
        // Given : en collation de langue, le tiret est ignoré (« tomate-2 » viendrait après « tomate »…) ; en octets, "-" (0x2D) précède "s" (0x73)
        $this->repository->save(IngredientDistinctPair::create(self::USER, 'tomates-u', 'tomate-u', new DateTimeImmutable()));

        // Then
        $this->assertSame([['first_slug' => 'tomate-u', 'second_slug' => 'tomates-u']], array_map(
            static fn(array $row): array => ['first_slug' => $row['first_slug'], 'second_slug' => $row['second_slug']],
            $this->rows(),
        ));
    }

    public function testDeletingByIngredientRemovesThePairsThatNameItOnEitherSideForThatUserOnly(): void
    {
        // Given
        $now = new DateTimeImmutable();
        foreach ([
            [self::USER, 'tomate', 'tomates'],
            [self::USER, 'courgette', 'tomate'],
            [self::USER, 'courgette', 'poivre'],
            [self::OTHER, 'tomate', 'tomates'],
        ] as [$user, $slug, $other]) {
            $this->repository->save(IngredientDistinctPair::create($user, $slug, $other, $now));
        }

        // When
        $this->repository->deleteByIngredientSlug(self::USER, 'tomate');

        // Then : ni la paire d'un autre ingrédient, ni celle d'un autre utilisateur
        $this->assertSame(
            [[self::OTHER, 'tomate', 'tomates'], [self::USER, 'courgette', 'poivre']],
            array_map(static fn(array $row): array => [$row['user_id'], $row['first_slug'], $row['second_slug']], $this->rows()),
        );
    }

    public function testDeletingByIngredientDoesNotConfuseTwoSlugsThatShareAPrefix(): void
    {
        // Given
        $this->repository->save(IngredientDistinctPair::create(self::USER, 'tomate', 'tomates', new DateTimeImmutable()));
        $this->repository->save(IngredientDistinctPair::create(self::USER, 'tomate-2', 'tomate-3', new DateTimeImmutable()));

        // When
        $this->repository->deleteByIngredientSlug(self::USER, 'tomate');

        // Then
        $this->assertSame([['tomate-2', 'tomate-3']], array_map(static fn(array $row): array => [$row['first_slug'], $row['second_slug']], $this->rows()));
    }

    public function testDeletingByUserRemovesHisPairsOnly(): void
    {
        // Given
        $this->repository->save(IngredientDistinctPair::create(self::USER, 'a', 'b', new DateTimeImmutable()));
        $this->repository->save(IngredientDistinctPair::create(self::OTHER, 'a', 'b', new DateTimeImmutable()));

        // When
        $this->repository->deleteByUser(self::USER);

        // Then
        $this->assertSame([self::OTHER], array_column($this->rows(), 'user_id'));
    }
}
