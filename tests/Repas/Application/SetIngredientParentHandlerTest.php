<?php

namespace Repas\Tests\Repas\Application;


use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\SetIngredientParent\SetIngredientParentCommand;
use Repas\Repas\Application\Command\SetIngredientParent\SetIngredientParentHandler;
use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Model\AliasKind;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\IngredientAlias;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\IngredientAliasInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;

/**
 * Découper un ingrédient (ADR 0038, SUB-001) : « Jaune d'œuf » devient une partie d'« Œuf ». Les invariants (pas lui-même, pas de chaîne, dix
 * parties au plus, aucune ligne dans une liste non terminée, ratio valide, tout à l'utilisateur) sont jugés AVANT la première écriture.
 */
class SetIngredientParentHandlerTest extends TestCase
{
    private IngredientInMemoryRepository $ingredientRepository;
    private ShoppingListInMemoryRepository $shoppingListRepository;
    private IngredientAliasInMemoryRepository $aliasRepository;
    private SetIngredientParentHandler $handler;
    /** @var int combien d'écritures le double a reçues */
    private int $saves = 0;

    protected function setUp(): void
    {
        $owner = new UserBuilder()->withId('user-1')->build();
        $other = new UserBuilder()->withId('user-2')->build();
        $this->saves = 0;
        $test = $this;
        $this->ingredientRepository = new class([
            $this->ingredient('oeuf', 'Œuf', $owner),
            $this->ingredient('jaune', "Jaune d'œuf", $owner),
            $this->ingredient('blanc', "Blanc d'œuf", $owner),
            $this->ingredient('vanille', 'Vanille', $owner),
            $this->ingredient('autre-oeuf', 'Œuf', $other),
            $this->ingredient('autre-jaune', "Jaune d'œuf", $other),
        ], $test) extends IngredientInMemoryRepository {
            public function __construct(array $models, private readonly SetIngredientParentHandlerTest $test)
            {
                parent::__construct($models);
            }

            public function save(ModelInterface $model): void
            {
                $this->test->countSave();
                parent::save($model);
            }
        };
        $this->shoppingListRepository = new ShoppingListInMemoryRepository();
        $this->aliasRepository = new IngredientAliasInMemoryRepository();
        $this->handler = new SetIngredientParentHandler($this->ingredientRepository, $this->shoppingListRepository, new IngredientSlugResolver($this->aliasRepository));
    }

    public function countSave(): void
    {
        ++$this->saves;
    }

    private function ingredient(string $slug, string $name, \Repas\User\Domain\Model\User $creator): Ingredient
    {
        return new IngredientBuilder()->isEgg()->withName($name)->withSlug($slug)->withCreator($creator)->build();
    }

    private function command(string $slug = 'jaune', string $parent = 'oeuf', float $ratio = 1.0, string $userId = 'user-1'): SetIngredientParentCommand
    {
        return new SetIngredientParentCommand($slug, $parent, $ratio, $userId);
    }

    /**
     * @return array{?string, ?float}
     */
    private function parentOf(string $slug): array
    {
        $ingredient = $this->ingredientRepository->findOneBySlug($slug);

        return [$ingredient->getParentSlug(), $ingredient->getParentRatio()];
    }

    private function addParts(string $parent, int $count): void
    {
        for ($i = 1; $i <= $count; ++$i) {
            $this->ingredientRepository->save(new IngredientBuilder()->isEgg()->withName("Partie $i")->withSlug("partie-$i")->withCreator(new UserBuilder()->withId('user-1')->build())->withParent($parent, 1.0)->build());
        }
        $this->saves = 0;
    }

    private function listWithRowOf(string $slug, ShoppingListStatus $status = ShoppingListStatus::ACTIVE): void
    {
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withOwner(new UserBuilder()->withId('user-1')->build())->addIngredient($this->ingredientRepository->findOneBySlug($slug))->withStatus($status)->build());
    }

    public function testAnIngredientBecomesAPartOfAnotherWithItsRatio(): void
    {
        // When
        ($this->handler)($this->command());

        // Then
        $this->assertSame(['oeuf', 1.0], $this->parentOf('jaune'));
        $this->assertSame([null, null], $this->parentOf('oeuf'), 'le parent ne change pas');
        $this->assertSame(1, $this->saves);
    }

    public function testTheSameCallTwiceIsIdempotentAndWritesNothingTheSecondTime(): void
    {
        // Given
        ($this->handler)($this->command(ratio: 2.0));
        $this->assertSame(1, $this->saves);

        // When
        ($this->handler)($this->command(ratio: 2.0));

        // Then
        $this->assertSame(['oeuf', 2.0], $this->parentOf('jaune'));
        $this->assertSame(1, $this->saves);
    }

    public function testChangingOnlyTheRatioWritesAndIsNeverRefused(): void
    {
        // Given : la partie est déjà reliée, le parent a déjà ses dix parties, et une liste en cours porte une ligne de l'ingrédient
        $this->addParts('oeuf', 9);
        ($this->handler)($this->command());
        $this->assertSame(10, $this->ingredientRepository->findAll()->filter(fn(Ingredient $i) => $i->getParentSlug() === 'oeuf')->count());
        $this->listWithRowOf('jaune');

        // When
        ($this->handler)($this->command(ratio: 3.0));

        // Then
        $this->assertSame(['oeuf', 3.0], $this->parentOf('jaune'));
    }

    public function testAPartChangesOfParent(): void
    {
        // Given
        ($this->handler)($this->command());

        // When
        ($this->handler)($this->command(parent: 'vanille', ratio: 0.5));

        // Then
        $this->assertSame(['vanille', 0.5], $this->parentOf('jaune'));
    }

    public function testTwoPartsOfTheSameParentAreAllowed(): void
    {
        // When
        ($this->handler)($this->command('jaune'));
        ($this->handler)($this->command('blanc'));

        // Then
        $this->assertSame(['oeuf', 'oeuf'], [$this->parentOf('jaune')[0], $this->parentOf('blanc')[0]]);
    }

    public function testAnIngredientIsNotItsOwnPart(): void
    {
        // Then
        $this->expectExceptionObject(IngredientException::partOfItself());

        // When
        ($this->handler)($this->command('jaune', 'jaune'));
    }

    public function testAnOldSlugOfTheIngredientIsItselfNotItsOwnPartEither(): void
    {
        // Given : l'ancien slug de la partie désigne le même ingrédient que son parent
        $this->aliasRepository->save(IngredientAlias::create('user-1', 'ancien-jaune', 'jaune', AliasKind::ADOPTION, 'activation', new DateTimeImmutable('2026-11-08 10:00:00')));

        // Then
        $this->expectExceptionObject(IngredientException::partOfItself());

        // When
        ($this->handler)($this->command('jaune', 'ancien-jaune'));
    }

    public function testTheParentCannotBeAPart(): void
    {
        // Given : « blanc » est une partie de « oeuf »
        ($this->handler)($this->command('blanc'));
        $this->saves = 0;

        // When / Then : une partie n'est pas un parent (profondeur 1)
        try {
            ($this->handler)($this->command('jaune', 'blanc'));
            $this->fail('La chaîne aurait dû être refusée.');
        } catch (IngredientException $exception) {
            $this->assertEquals(IngredientException::partChain(), $exception);
        }
        $this->assertSame([null, null], $this->parentOf('jaune'));
        $this->assertSame(0, $this->saves);
    }

    public function testAnIngredientThatHasPartsCannotBecomeAPart(): void
    {
        // Given : « oeuf » a une partie ; on veut en faire une partie de « vanille »
        ($this->handler)($this->command('jaune', 'oeuf'));
        $this->saves = 0;

        // When / Then : une partie n'a pas de partie (profondeur 1), dans l'autre sens
        try {
            ($this->handler)($this->command('oeuf', 'vanille'));
            $this->fail('La chaîne aurait dû être refusée.');
        } catch (IngredientException $exception) {
            $this->assertEquals(IngredientException::partChain(), $exception);
        }
        $this->assertSame([null, null], $this->parentOf('oeuf'));
        $this->assertSame(0, $this->saves);
    }

    public function testTwoIngredientsCannotBePartsOfEachOther(): void
    {
        // Given
        ($this->handler)($this->command('jaune', 'oeuf'));

        // Then : le cycle est une chaîne
        $this->expectExceptionObject(IngredientException::partChain());

        // When
        ($this->handler)($this->command('oeuf', 'jaune'));
    }

    public function testAParentHasTenPartsAtMost(): void
    {
        // Given : dix parties
        $this->addParts('oeuf', Ingredient::MAX_PARTS);

        // When / Then : la onzième est refusée, rien n'est écrit
        try {
            ($this->handler)($this->command());
            $this->fail('Le plafond aurait dû être refusé.');
        } catch (IngredientException $exception) {
            $this->assertEquals(IngredientException::partLimitReached(), $exception);
        }
        $this->assertSame([null, null], $this->parentOf('jaune'));
        $this->assertSame(0, $this->saves);
    }

    public function testTheTenthPartIsAccepted(): void
    {
        // Given
        $this->addParts('oeuf', Ingredient::MAX_PARTS - 1);

        // When
        ($this->handler)($this->command());

        // Then
        $this->assertSame(['oeuf', 1.0], $this->parentOf('jaune'));
    }

    public function testAnotherParentWithRoomStillAcceptsAPartOfAFullParent(): void
    {
        // Given : « oeuf » est plein, « vanille » est libre
        $this->addParts('oeuf', Ingredient::MAX_PARTS);

        // When
        ($this->handler)($this->command(parent: 'vanille'));

        // Then
        $this->assertSame(['vanille', 1.0], $this->parentOf('jaune'));
    }

    public function testTheParentOfAnotherUserIsNotFoundAndNothingIsWritten(): void
    {
        // When / Then
        try {
            ($this->handler)($this->command(parent: 'autre-oeuf'));
            $this->fail('Le parent d\'un autre aurait dû être introuvable.');
        } catch (IngredientException $exception) {
            $this->assertEquals(IngredientException::notFound('autre-oeuf'), $exception);
        }
        $this->assertSame([null, null], $this->parentOf('jaune'));
        $this->assertSame(0, $this->saves);
    }

    public function testTheIngredientOfAnotherUserIsNotFoundWhoeverAsksAdministratorIncluded(): void
    {
        // Given : la commande ne porte que l'identifiant de l'utilisateur du jeton, jamais son rôle : aucun passe-droit n'est possible
        $this->expectExceptionObject(IngredientException::notFound('autre-jaune'));

        try {
            // When
            ($this->handler)($this->command('autre-jaune', 'autre-oeuf', 1.0, 'user-1'));
        } finally {
            // Then
            $this->assertSame([null, null], $this->parentOf('autre-jaune'));
            $this->assertSame(0, $this->saves);
        }
    }

    public function testAnUnknownIngredientOrParentIsNotFound(): void
    {
        foreach ([$this->command('inconnu'), $this->command(parent: 'inconnu')] as $command) {
            try {
                ($this->handler)($command);
                $this->fail('Un slug inconnu aurait dû être refusé.');
            } catch (IngredientException $exception) {
                $this->assertSame(404, $exception->getCode());
            }
        }
        $this->assertSame(0, $this->saves);
    }

    /**
     * @return iterable<string, array{ShoppingListStatus}>
     */
    public static function openStatuses(): iterable
    {
        yield 'en cours' => [ShoppingListStatus::ACTIVE];
        yield 'en pause' => [ShoppingListStatus::PAUSED];
        yield 'envoyée au drive' => [ShoppingListStatus::SENT];
    }

    #[DataProvider('openStatuses')]
    public function testAnIngredientWithARowInAnOpenListCannotBeCutAndNothingIsWritten(ShoppingListStatus $status): void
    {
        // Given
        $this->listWithRowOf('jaune', $status);

        // When / Then
        try {
            ($this->handler)($this->command());
            $this->fail('La ligne dans une liste non terminée aurait dû être refusée.');
        } catch (IngredientException $exception) {
            $this->assertEquals(IngredientException::partInOpenList(), $exception);
        }
        $this->assertSame([null, null], $this->parentOf('jaune'));
        $this->assertSame(0, $this->saves);
    }

    public function testACompletedListDoesNotStopTheCut(): void
    {
        // Given : une liste terminée garde sa ligne, elle ne compte plus
        $this->listWithRowOf('jaune', ShoppingListStatus::COMPLETED);

        // When
        ($this->handler)($this->command());

        // Then
        $this->assertSame(['oeuf', 1.0], $this->parentOf('jaune'));
    }

    public function testARowOfAnotherIngredientDoesNotStopTheCut(): void
    {
        // Given : la liste ne porte que le parent
        $this->listWithRowOf('oeuf');

        // When
        ($this->handler)($this->command());

        // Then
        $this->assertSame(['oeuf', 1.0], $this->parentOf('jaune'));
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function invalidRatios(): iterable
    {
        yield 'nul' => [0.0];
        yield 'négatif' => [-2.0];
        yield 'énorme' => [1_000_001.0];
        yield 'infini' => [INF];
        yield 'pas un nombre' => [NAN];
    }

    #[DataProvider('invalidRatios')]
    public function testAnInvalidRatioIsRefusedAndNothingIsWritten(float $ratio): void
    {
        // When / Then
        try {
            ($this->handler)($this->command(ratio: $ratio));
            $this->fail('Le ratio aurait dû être refusé.');
        } catch (IngredientException $exception) {
            $this->assertEquals(IngredientException::partRatioInvalid(), $exception);
        }
        $this->assertSame([null, null], $this->parentOf('jaune'));
        $this->assertSame(0, $this->saves);
    }

    public function testAnInvalidRatioIsRefusedEvenForAPartAlreadyLinkedAndItsRatioIsKept(): void
    {
        // Given
        ($this->handler)($this->command(ratio: 2.0));

        // When / Then
        try {
            ($this->handler)($this->command(ratio: 0.0));
            $this->fail('Le ratio aurait dû être refusé.');
        } catch (IngredientException $exception) {
            $this->assertEquals(IngredientException::partRatioInvalid(), $exception);
        }
        $this->assertSame(['oeuf', 2.0], $this->parentOf('jaune'));
    }

    public function testBothSlugsAreTranslatedByTheAliasesOfTheUser(): void
    {
        // Given : les anciens slugs (un commun dont l'utilisateur a reçu une copie, un ingrédient fusionné) désignent les ingrédients actuels
        $this->aliasRepository->save(IngredientAlias::create('user-1', 'ancien-jaune', 'jaune', AliasKind::ADOPTION, 'activation', new DateTimeImmutable('2026-11-08 10:00:00')));
        $this->aliasRepository->save(IngredientAlias::create('user-1', 'ancien-oeuf', 'oeuf', AliasKind::MERGE, 'merge', new DateTimeImmutable('2026-11-08 10:00:00')));

        // When
        ($this->handler)($this->command('ancien-jaune', 'ancien-oeuf', 1.0));

        // Then : le lien est posé sur les slugs actuels
        $this->assertSame(['oeuf', 1.0], $this->parentOf('jaune'));
    }
}
