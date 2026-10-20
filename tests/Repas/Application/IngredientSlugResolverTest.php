<?php

namespace Repas\Tests\Repas\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Repas\Domain\Model\AliasKind;
use Repas\Repas\Domain\Model\IngredientAlias;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\IngredientSlugResolverFactory;
use Repas\Tests\Helper\InMemoryRepository\IngredientAliasInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;

/**
 * Traduire les slugs reçus en écriture (ADR 0028 décision 4, ING-002). Les communs sont la farine, le beurre et le lait ; `USER` a
 * adopté la farine ; `OTHER` n'a rien adopté ; l'œuf est l'ingrédient personnel de `USER`, la pâte celui d'un autre.
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
            new IngredientBuilder()->isFlour()->build(),
            new IngredientBuilder()->isButter()->build(),
            new IngredientBuilder()->isMilk()->build(),
            new IngredientBuilder()->isFlour()->withCreator($user, true)->withAdoptedFrom('farine')->build(),
            new IngredientBuilder()->isEgg()->withCreator($user, true)->build(),
            new IngredientBuilder()->isPasta()->withCreator(new UserBuilder()->withId(self::STRANGER)->build(), true)->build(),
        ]);
        $this->aliases = new IngredientAliasInMemoryRepository([$this->alias(self::USER, 'farine', 'farine' . self::USER)]);
        $this->resolver = IngredientSlugResolverFactory::create($this->ingredients, $this->aliases);
    }

    public function testAnAdoptedCommonIsReplacedByTheCopyOfTheUser(): void
    {
        // When
        $resolved = $this->resolver->resolveForUser(self::USER, ['farine']);

        // Then
        $this->assertSame(['farine' => 'farine' . self::USER], $resolved);
    }

    public function testAnAdopterAdoptsOnTheFlyTheCommonHeUsesForTheFirstTime(): void
    {
        // When
        $resolved = $this->resolver->resolveForUser(self::USER, ['beurre', 'farine']);

        // Then : le beurre est adopté (copie, alias `first-use`), la farine l'était déjà
        $this->assertSame(['farine' => 'farine' . self::USER, 'beurre' => 'beurre' . self::USER], $resolved);
        $copy = $this->ingredients->findOneBySlug('beurre' . self::USER);
        $this->assertSame([self::USER, 'beurre'], [$copy->getCreatorId(), $copy->getAdoptedFrom()]);
        $alias = $this->aliases->find(self::USER, 'beurre');
        $this->assertSame([AliasKind::ADOPTION, 'first-use', 'beurre' . self::USER], [$alias->getKind(), $alias->getSource(), $alias->getIngredientSlug()]);
        // Le commun, lui, est resté tel quel
        $this->assertNull($this->ingredients->findOneBySlug('beurre')->getCreatorId());
    }

    public function testAnotherCommonIsAdoptedOnlyOnce(): void
    {
        // When
        $first = $this->resolver->resolveForUser(self::USER, ['lait']);
        $second = $this->resolver->resolveForUser(self::USER, ['lait']);

        // Then
        $this->assertSame($first, $second);
        $this->assertCount(2, $this->aliases->all());
        $this->assertCount(7, $this->ingredients->findAll());
    }

    public function testAUserWhoAdoptedNothingKeepsTheSharedCommons(): void
    {
        // When : tous les slugs sont ceux d'un commun, y compris la farine que USER a adoptée
        $resolved = $this->resolver->resolveForUser(self::OTHER, ['farine', 'beurre', 'lait']);

        // Then : rien ne change et rien n'est adopté (le comportement d'avant ce chantier)
        $this->assertSame([], $resolved);
        $this->assertCount(1, $this->aliases->all());
        $this->assertCount(6, $this->ingredients->findAll());
    }

    public function testAnAdministratorWithoutAnyAliasKeepsTheCommons(): void
    {
        // When : un administrateur n'a aucun alias, il n'est donc pas adoptant
        $resolved = $this->resolver->resolveForUser('admin-id', ['farine', 'beurre']);

        // Then
        $this->assertSame([], $resolved);
        $this->assertSame([], array_filter($this->aliases->all(), static fn(IngredientAlias $alias): bool => $alias->getUserId() === 'admin-id'));
    }

    public function testAMergeAliasIsResolvedForAUserWhoAdoptedNothing(): void
    {
        // Given : un utilisateur qui a fusionné deux de ses ingrédients (ING-005) mais n'a adopté aucun commun
        $this->aliases->save($this->alias(self::OTHER, 'ancien', 'nouveau' . self::OTHER, AliasKind::MERGE));

        // When
        $resolved = $this->resolver->resolveForUser(self::OTHER, ['ancien', 'beurre']);

        // Then : l'alias de fusion répond, le commun reste le commun
        $this->assertSame(['ancien' => 'nouveau' . self::OTHER], $resolved);
        $this->assertCount(2, $this->aliases->all());
    }

    public function testAnOrphanAliasIsHealedByAdoptingTheCommonAgain(): void
    {
        // Given : la copie du beurre a disparu (le code d'avant ING-001 supprime une copie sans effacer son alias) ; son seul alias est orphelin,
        // l'utilisateur reste adoptant
        $aliases = new IngredientAliasInMemoryRepository(
            [$this->alias(self::OTHER, 'beurre', 'beurre-disparu')],
            fn(string $userId, string $slug): bool => $this->ingredients->exists($slug),
        );
        $resolver = IngredientSlugResolverFactory::create($this->ingredients, $aliases);

        // When
        $resolved = $resolver->resolveForUser(self::OTHER, ['beurre', 'lait']);

        // Then : ni l'alias orphelin n'est rendu, ni le commun gardé : les deux sont adoptés, l'alias du beurre est remplacé
        $this->assertSame(['beurre' => 'beurre' . self::OTHER, 'lait' => 'lait' . self::OTHER], $resolved);
        $this->assertSame('beurre' . self::OTHER, $aliases->find(self::OTHER, 'beurre')->getIngredientSlug());
        $this->assertTrue($this->ingredients->exists('beurre' . self::OTHER));
    }

    public function testThePersonalAndTheUnknownSlugsAreNeverTouched(): void
    {
        // When : l'ingrédient personnel de l'utilisateur, celui d'un autre (traité comme inconnu plus loin), un slug inconnu
        $resolved = $this->resolver->resolveForUser(self::USER, ['oeuf' . self::USER, 'pate' . self::STRANGER, 'inconnu']);

        // Then : aucun n'est un commun, aucun n'est adopté, les contrôles du cas d'usage répondent comme avant
        $this->assertSame([], $resolved);
        $this->assertCount(1, $this->aliases->all());
        $this->assertCount(6, $this->ingredients->findAll());
    }

    public function testTheSameSlugReceivedTwiceIsResolvedOnce(): void
    {
        // When
        $resolved = $this->resolver->resolveForUser(self::USER, ['beurre', 'beurre', 'farine', 'farine']);

        // Then
        $this->assertSame(['farine' => 'farine' . self::USER, 'beurre' => 'beurre' . self::USER], $resolved);
        $this->assertCount(2, $this->aliases->all());
    }

    public function testNoSlugMeansNothingToResolve(): void
    {
        $this->assertSame([], $this->resolver->resolveForUser(self::USER, []));
        $this->assertSame([], $this->resolver->resolveExisting(self::USER, []));
    }

    public function testResolvingWithoutAdoptingNeverAdopts(): void
    {
        // When : retrouver ce que l'utilisateur a déjà, sans rien adopter
        $resolved = $this->resolver->resolveExisting(self::USER, ['farine', 'beurre', 'lait']);

        // Then : seule la farine, déjà adoptée, répond
        $this->assertSame(['farine' => 'farine' . self::USER], $resolved);
        $this->assertCount(1, $this->aliases->all());
        $this->assertCount(6, $this->ingredients->findAll());
    }

    private function alias(string $userId, string $aliasSlug, string $ingredientSlug, AliasKind $kind = AliasKind::ADOPTION): IngredientAlias
    {
        return IngredientAlias::create($userId, $aliasSlug, $ingredientSlug, $kind, 'migration-2026-10-21', new DateTimeImmutable('2026-10-21 03:00:00'));
    }
}
