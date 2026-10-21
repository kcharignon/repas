<?php

namespace Repas\Tests\Repas\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Repas\Domain\Model\AliasKind;
use Repas\Repas\Domain\Model\IngredientAlias;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\IngredientAliasInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;

/**
 * Traduire les slugs reçus en écriture (ADR 0028 décision 4, réduite par l'amendement du 2026-10-20) : seuls les alias qui existent
 * répondent, jamais d'adoption à la volée ni de notion d'« adoptant ». `USER` a reçu une copie de la farine (alias d'adoption) et a fusionné
 * deux ingrédients (alias de fusion) ; `OTHER` n'a aucun alias ; l'œuf est l'ingrédient de `USER`, la pâte celui d'un autre.
 */
class IngredientSlugResolverTest extends TestCase
{
    private const string USER = 'user-id';
    private const string OTHER = 'other-id';
    private const string STRANGER = 'stranger-id';

    private IngredientInMemoryRepository $ingredients;
    private IngredientAliasInMemoryRepository $aliases;
    private IngredientSlugResolver $resolver;

    protected function setUp(): void
    {
        $user = new UserBuilder()->withId(self::USER)->build();
        $this->ingredients = new IngredientInMemoryRepository([
            new IngredientBuilder()->isFlour()->withCreator(new UserBuilder()->withId('owner-id')->build())->build(),
            new IngredientBuilder()->isFlour()->withCreator($user, true)->withAdoptedFrom('farine')->build(),
            new IngredientBuilder()->isEgg()->withCreator($user, true)->build(),
            new IngredientBuilder()->isPasta()->withCreator(new UserBuilder()->withId(self::STRANGER)->build(), true)->build(),
        ]);
        $this->aliases = new IngredientAliasInMemoryRepository([
            $this->alias(self::USER, 'farine', 'farine' . self::USER),
            $this->alias(self::USER, 'oeufs', 'oeuf' . self::USER, AliasKind::MERGE),
        ]);
        $this->resolver = new IngredientSlugResolver($this->aliases);
    }

    public function testAnOldSlugIsReplacedByTheIngredientOfTheUser(): void
    {
        // When
        $resolved = $this->resolver->resolve(self::USER, ['farine']);

        // Then : l'ancien commun (devenu un ingrédient du propriétaire du catalogue) désigne la copie de l'utilisateur
        $this->assertSame(['farine' => 'farine' . self::USER], $resolved);
    }

    public function testAMergeAliasIsResolvedLikeAnAdoptionAlias(): void
    {
        // When
        $resolved = $this->resolver->resolve(self::USER, ['oeufs', 'farine']);

        // Then
        $this->assertSame(['oeufs' => 'oeuf' . self::USER, 'farine' => 'farine' . self::USER], $resolved);
    }

    public function testAUserWithoutAnyAliasKeepsEverySlugAndNothingIsWritten(): void
    {
        // When : un compte qui n'a aucun alias (un compte amorcé n'en a pas pour les ingrédients qu'il a créés)
        $resolved = $this->resolver->resolve(self::OTHER, ['farine', 'beurre', 'lait']);

        // Then : rien ne change, rien n'est adopté : le cas d'usage qui suit répond (ingrédient inconnu ou à un autre)
        $this->assertSame([], $resolved);
        $this->assertCount(2, $this->aliases->all());
        $this->assertCount(4, $this->ingredients->findAll());
    }

    public function testAnAdministratorIsAnOrdinaryUser(): void
    {
        // When : aucun traitement particulier : sans alias, les slugs sont gardés
        $resolved = $this->resolver->resolve('admin-id', ['farine', 'beurre']);

        // Then
        $this->assertSame([], $resolved);
        $this->assertSame([], array_filter($this->aliases->all(), static fn(IngredientAlias $alias): bool => $alias->getUserId() === 'admin-id'));
    }

    public function testAnUnknownSlugOrTheIngredientOfAnotherUserIsNeverTouched(): void
    {
        // When : l'ingrédient de l'utilisateur, celui d'un autre (inconnu pour lui, la suite du cas d'usage le refuse), un slug inconnu
        $resolved = $this->resolver->resolve(self::USER, ['oeuf' . self::USER, 'pate' . self::STRANGER, 'inconnu']);

        // Then
        $this->assertSame([], $resolved);
    }

    public function testAnOrphanAliasIsIgnored(): void
    {
        // Given : l'alias désigne un ingrédient qui n'existe plus ou n'est plus à l'utilisateur (retour arrière du code, copie supprimée)
        $aliases = new IngredientAliasInMemoryRepository(
            [$this->alias(self::OTHER, 'beurre', 'beurre-disparu')],
            fn(string $userId, string $slug): bool => $this->ingredients->exists($slug),
        );

        // When
        $resolved = new IngredientSlugResolver($aliases)->resolve(self::OTHER, ['beurre']);

        // Then : le slug reçu est gardé, il n'y a plus rien à adopter à la place
        $this->assertSame([], $resolved);
        $this->assertCount(4, $this->ingredients->findAll());
    }

    public function testTheSameSlugReceivedTwiceIsResolvedOnce(): void
    {
        // When
        $resolved = $this->resolver->resolve(self::USER, ['farine', 'farine']);

        // Then
        $this->assertSame(['farine' => 'farine' . self::USER], $resolved);
    }

    public function testNoSlugMeansNothingToResolve(): void
    {
        $this->assertSame([], $this->resolver->resolve(self::USER, []));
    }

    private function alias(string $userId, string $aliasSlug, string $ingredientSlug, AliasKind $kind = AliasKind::ADOPTION): IngredientAlias
    {
        return IngredientAlias::create($userId, $aliasSlug, $ingredientSlug, $kind, 'migration-2026-10-21', new DateTimeImmutable('2026-10-21 03:00:00'));
    }
}
