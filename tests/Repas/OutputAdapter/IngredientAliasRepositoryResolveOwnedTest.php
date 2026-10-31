<?php

namespace Repas\Tests\Repas\OutputAdapter;


use DateTimeImmutable;
use Repas\Repas\Domain\Interface\IngredientAliasRepository;
use Repas\Repas\Domain\Model\AliasKind;
use Repas\Repas\Domain\Model\IngredientAlias;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\SqlBudget\QueryCounter;

/**
 * Le port du dépôt des alias que le contexte `Drive` interroge avant d'écrire un choix (par l'Open Host Service de `Repas`), sur la vraie base : à quel ingrédient de l'utilisateur répond un
 * slug reçu ? `resolve()` ne traduit que les alias valides (testé avec le dépôt des alias) ; `resolveOwned()` y ajoute l'ingrédient lui-même,
 * s'il est à l'utilisateur, en une seule requête.
 */
class IngredientAliasRepositoryResolveOwnedTest extends DatabaseTestCase
{
    private const string USER = 'user-id';
    private const string OTHER = 'other-id';

    private IngredientAliasRepository $resolver;
    private IngredientAliasRepository $aliases;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = static::getContainer()->get(IngredientAliasRepository::class);
        $this->aliases = static::getContainer()->get(IngredientAliasRepository::class);
        $this->persister = new DataPersister(static::getContainer());
    }

    public function testASlugOfAnIngredientOfTheUserAnswersToItself(): void
    {
        // Given
        $this->ingredient(self::USER, 'tomate-user-id');

        // When
        $owned = $this->resolver->resolveOwned(self::USER, ['tomate-user-id']);

        // Then
        $this->assertSame(['tomate-user-id' => 'tomate-user-id'], $owned);
    }

    public function testTheSlugOfAnotherUsersIngredientAnswersToNothing(): void
    {
        // Given : l'ingrédient existe, mais il est à un autre compte
        $this->ingredient(self::OTHER, 'tomate-other-id');

        // When
        $owned = $this->resolver->resolveOwned(self::USER, ['tomate-other-id']);

        // Then : indiscernable d'un slug qui n'existe pas
        $this->assertSame([], $owned);
        $this->assertSame([], $this->resolver->resolveOwned(self::USER, ['inconnu']));
    }

    public function testAnAdministratorOwnsOnlyHisOwnIngredientsToo(): void
    {
        // Given : la règle est « créateur = moi », sans passe-droit
        $this->ingredient(self::OTHER, 'tomate-other-id');
        $this->ingredient(self::USER, 'carotte-user-id');

        // When
        $owned = $this->resolver->resolveOwned(self::USER, ['tomate-other-id', 'carotte-user-id']);

        // Then
        $this->assertSame(['carotte-user-id' => 'carotte-user-id'], $owned);
    }

    public function testAnOldSlugAnswersToTheIngredientTheAliasDesignates(): void
    {
        // Given : l'ancien commun « tomate » est devenu « tomate-user-id » chez l'utilisateur ; l'ancien slug est celui d'un ingrédient d'un autre compte
        $this->ingredient(self::OTHER, 'tomate');
        $this->ingredient(self::USER, 'tomate-user-id');
        $this->alias(self::USER, 'tomate', 'tomate-user-id');

        // When
        $owned = $this->resolver->resolveOwned(self::USER, ['tomate', 'tomate-user-id']);

        // Then : l'alias l'emporte, comme pour `resolve()` ; le nouveau slug reste lui-même
        $this->assertEquals(['tomate' => 'tomate-user-id', 'tomate-user-id' => 'tomate-user-id'], $owned);
        $this->assertEquals(['tomate' => 'tomate-user-id'], $this->resolver->resolve(self::USER, ['tomate', 'tomate-user-id']));
    }

    public function testAnAliasWhoseIngredientIsNotTheUsersAnswersToNothing(): void
    {
        // Given : un alias orphelin (l'ingrédient désigné a disparu) vers un slug qui n'est pas non plus un ingrédient de l'utilisateur
        $this->ingredient(self::OTHER, 'tomate');
        $this->alias(self::USER, 'tomate', 'tomate-user-id');

        // When
        $owned = $this->resolver->resolveOwned(self::USER, ['tomate']);

        // Then : le slug reçu est celui d'un autre, et l'alias ne désigne plus rien de lui
        $this->assertSame([], $owned);
    }

    public function testTheAliasOfAnotherUserDoesNotCount(): void
    {
        // Given
        $this->ingredient(self::OTHER, 'tomate-other-id');
        $this->alias(self::OTHER, 'tomate', 'tomate-other-id');

        // When
        $owned = $this->resolver->resolveOwned(self::USER, ['tomate', 'tomate-other-id']);

        // Then
        $this->assertSame([], $owned);
    }

    public function testManySlugsCostOneQueryAndAnEmptyListCostsNone(): void
    {
        // Given
        $this->ingredient(self::USER, 'tomate-user-id');
        $this->ingredient(self::USER, 'carotte-user-id');
        $this->ingredient(self::OTHER, 'poireau-other-id');
        $this->alias(self::USER, 'oignon', 'carotte-user-id');
        static::getContainer()->get('doctrine')->getManager()->clear();
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $owned = $this->resolver->resolveOwned(self::USER, ['tomate-user-id', 'oignon', 'poireau-other-id', 'inconnu', 'tomate-user-id']);

        // Then
        $this->assertEquals(['tomate-user-id' => 'tomate-user-id', 'oignon' => 'carotte-user-id'], $owned);
        $this->assertSame(1, array_sum($counter->byTable()));

        // When
        $counter->reset();

        // Then
        $this->assertSame([], $this->resolver->resolveOwned(self::USER, []));
        $this->assertSame([], $counter->byTable());
    }

    private function ingredient(string $userId, string $slug): void
    {
        $this->persister->saveIngredient(new IngredientBuilder()->isButter()->withCreator(new UserBuilder()->withId($userId)->build())->withSlug($slug)->build());
    }

    private function alias(string $userId, string $aliasSlug, string $ingredientSlug): void
    {
        $this->aliases->save(IngredientAlias::create($userId, $aliasSlug, $ingredientSlug, AliasKind::ADOPTION, 'first-use', new DateTimeImmutable('2026-10-21 03:04:05')));
    }
}
