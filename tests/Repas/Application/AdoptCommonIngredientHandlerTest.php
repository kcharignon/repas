<?php

namespace Repas\Tests\Repas\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\AdoptCommonIngredient\AdoptCommonIngredientCommand;
use Repas\Repas\Application\Command\AdoptCommonIngredient\AdoptCommonIngredientHandler;
use Repas\Repas\Domain\Exception\IngredientAliasException;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Model\AliasKind;
use Repas\Repas\Domain\Model\Conversion;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\IngredientAlias;
use Repas\Repas\Domain\Service\IngredientSlugPolicy;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\ConversionInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientAliasInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\RepasAssert;

/**
 * Adopter un commun pour un utilisateur (ADR 0028, ING-001). Le commun est le beurre, qui a deux conversions propres (un bloc, une
 * cuillère) ; le kilo vaut mille grammes pour tout le monde (conversion commune, jamais copiée).
 */
class AdoptCommonIngredientHandlerTest extends TestCase
{
    private const string USER = 'user-id';
    private const string OTHER = 'other-id';
    private const string NOW = '2026-10-21 03:00:00';

    private IngredientInMemoryRepository $ingredientRepository;
    private ConversionInMemoryRepository $conversionRepository;
    private IngredientAliasInMemoryRepository $aliasRepository;
    private AdoptCommonIngredientHandler $handler;

    protected function setUp(): void
    {
        $this->ingredientRepository = new IngredientInMemoryRepository([
            new IngredientBuilder()->isButter()->build(),
            new IngredientBuilder()->isFlour()->build(),
            new IngredientBuilder()->isMilk()->withCreator(new UserBuilder()->withId(self::OTHER)->build(), true)->build(),
        ]);
        $this->conversionRepository = new ConversionInMemoryRepository([
            new ConversionBuilder()->isBlockToGrammeForButter()->build(),
            new ConversionBuilder()->withId('butter-spoon')->withStartUnit(new UnitBuilder()->isSoupSpoon())->withEndUnit(new UnitBuilder()->isGramme())->withCoefficient(15)->withIngredient(new IngredientBuilder()->isButter())->build(),
            new ConversionBuilder()->isKiloToGramme()->withId('common-kilo')->build(),
            new ConversionBuilder()->isUnitToGrammeForEgg()->withId('egg-unit')->build(),
        ]);
        $this->aliasRepository = new IngredientAliasInMemoryRepository();

        $this->handler = $this->handlerWith($this->aliasRepository);
    }

    public function testAdoptingACommonCreatesThePersonalCopyOfTheUser(): void
    {
        // When
        $slug = $this->adopt('beurre');

        // Then : l'ingrédient de l'utilisateur, copie conforme à l'origine près (créateur, origine)
        $this->assertSame('beurre' . self::USER, $slug);
        $copy = $this->ingredientRepository->findOneBySlug($slug);
        $common = new IngredientBuilder()->isButter()->build();
        $this->assertSame([self::USER, 'beurre', true], [$copy->getCreatorId(), $copy->getAdoptedFrom(), $copy->isAdoption()]);
        $this->assertSame([$common->getName(), $common->getSluggedName(), $common->getImage()], [$copy->getName(), $copy->getSluggedName(), $copy->getImage()]);
        RepasAssert::assertDepartment($common->getDepartment(), $copy->getDepartment());
        RepasAssert::assertUnit($common->getDefaultCookingUnit(), $copy->getDefaultCookingUnit());
        RepasAssert::assertUnit($common->getDefaultPurchaseUnit(), $copy->getDefaultPurchaseUnit());
        $this->assertSame(
            array_map(static fn($unit) => $unit->getSlug(), array_values($common->getCompatibleUnits()->toArray())),
            array_map(static fn($unit) => $unit->getSlug(), array_values($copy->getCompatibleUnits()->toArray())),
        );
    }

    public function testTheOwnConversionsOfTheCommonAreCopiedWithNewIdentifiers(): void
    {
        // Given
        $common = $this->ingredientRepository->findOneBySlug('beurre');
        $originals = $this->conversionsOf($common);

        // When
        $slug = $this->adopt('beurre');

        // Then : même nombre, mêmes unités et coefficients, nouveaux identifiants, rattachées à la copie
        $copy = $this->ingredientRepository->findOneBySlug($slug);
        $copies = $this->conversionsOf($copy);
        $this->assertCount(2, $copies);
        $this->assertSame($this->shape($originals), $this->shape($copies));
        $this->assertSame([], array_intersect(array_keys($originals), array_keys($copies)), 'les identifiants sont neufs');
        foreach ($copies as $conversion) {
            $this->assertTrue($copy->isEqual($conversion->getIngredient()));
        }
        // Le commun garde les siennes, rien d'autre n'est copié (ni la conversion commune, ni celle d'un autre ingrédient)
        $this->assertSame(array_keys($originals), array_keys($this->conversionsOf($common)));
        $this->assertCount(6, $this->conversionRepository->findAll());
        $this->assertCount(1, $this->conversionRepository->findAll()->filter(fn(Conversion $c) => $c->getIngredient() === null));
    }

    public function testTheAliasOfTheCommonToTheCopyIsWritten(): void
    {
        // When
        $slug = $this->adopt('beurre', 'migration-2026-10-21');

        // Then
        $alias = $this->aliasRepository->find(self::USER, 'beurre');
        $this->assertNotNull($alias);
        $this->assertSame([self::USER, 'beurre', $slug, AliasKind::ADOPTION, 'migration-2026-10-21'], [
            $alias->getUserId(), $alias->getAliasSlug(), $alias->getIngredientSlug(), $alias->getKind(), $alias->getSource(),
        ]);
        $this->assertEquals(new DateTimeImmutable(self::NOW), $alias->getCreatedAt());
        $this->assertTrue($this->aliasRepository->isAdopter(self::USER));
        $this->assertFalse($this->aliasRepository->isAdopter(self::OTHER));
        $this->assertCount(1, $this->aliasRepository->all());
    }

    public function testTheCommonIsLeftUntouched(): void
    {
        // Given
        $before = $this->ingredientRepository->findOneBySlug('beurre')->toArray();

        // When
        $this->adopt('beurre');

        // Then
        $common = $this->ingredientRepository->findOneBySlug('beurre');
        $this->assertSame($before, $common->toArray());
        $this->assertNull($common->getCreatorId());
        $this->assertNull($common->getAdoptedFrom());
    }

    public function testAdoptingAgainCreatesNoDuplicateAndReturnsTheSameSlug(): void
    {
        // Given
        $first = $this->adopt('beurre');
        $ingredients = count($this->ingredientRepository->findAll());
        $conversions = count($this->conversionRepository->findAll());

        // When
        $second = $this->adopt('beurre', 'first-use');

        // Then : ni ingrédient, ni conversion, ni alias de plus ; la source du premier passage reste
        $this->assertSame($first, $second);
        $this->assertCount($ingredients, $this->ingredientRepository->findAll());
        $this->assertCount($conversions, $this->conversionRepository->findAll());
        $this->assertCount(1, $this->aliasRepository->all());
        $this->assertSame('copy', $this->aliasRepository->find(self::USER, 'beurre')->getSource());
    }

    public function testAnAdoptionAlreadyDoneStaysResolvedEvenIfTheCommonIsGone(): void
    {
        // Given : l'utilisateur a adopté le beurre, puis un administrateur a supprimé le commun
        $slug = $this->adopt('beurre');
        $this->ingredientRepository->delete($this->ingredientRepository->findOneBySlug('beurre'));

        // When : l'alias répond, sans relire le commun
        $again = $this->adopt('beurre');

        // Then
        $this->assertSame($slug, $again);
        $this->assertTrue($this->ingredientRepository->exists($slug));
    }

    public function testAnOrphanAliasIsErasedAndTheCommonIsAdoptedAgain(): void
    {
        // Given : le code d'avant ING-001 a supprimé la copie sans effacer son alias (retour arrière après la migration)
        $orphan = IngredientAlias::create(self::USER, 'beurre', 'beurre-d-avant-' . self::USER, AliasKind::ADOPTION, 'migration-2026-10-21', new DateTimeImmutable(self::NOW));
        $aliases = new IngredientAliasInMemoryRepository([$orphan], fn(string $userId, string $slug): bool => $this->ingredientRepository->exists($slug));
        $conversions = count($this->conversionRepository->findAll());

        // When
        $slug = $this->handlerWith($aliases)(new AdoptCommonIngredientCommand(self::USER, 'beurre', 'first-use'));

        // Then : une copie neuve, avec ses conversions, et un alias valide à la place de l'orphelin (jamais l'orphelin rendu)
        $this->assertSame('beurre' . self::USER, $slug);
        $this->assertTrue($this->ingredientRepository->exists($slug));
        $this->assertCount($conversions + 2, $this->conversionRepository->findAll());
        $alias = $aliases->find(self::USER, 'beurre');
        $this->assertSame([$slug, 'first-use'], [$alias->getIngredientSlug(), $alias->getSource()]);
        $this->assertCount(1, $aliases->all());
    }

    public function testAnAliasWhoseCopyStillExistsIsNotTouchedByTheOrphanCheck(): void
    {
        // Given
        $aliases = new IngredientAliasInMemoryRepository([], fn(string $userId, string $slug): bool => $this->ingredientRepository->exists($slug));
        $handler = $this->handlerWith($aliases);
        $slug = $handler(new AdoptCommonIngredientCommand(self::USER, 'beurre', 'copy'));

        // When
        $again = $handler(new AdoptCommonIngredientCommand(self::USER, 'beurre', 'first-use'));

        // Then : la même copie, le premier alias (sa source) intact
        $this->assertSame($slug, $again);
        $this->assertSame('copy', $aliases->find(self::USER, 'beurre')->getSource());
        $this->assertCount(1, $aliases->all());
    }

    public function testAnotherUserAdoptsHisOwnCopy(): void
    {
        // Given
        $mine = $this->adopt('beurre');

        // When
        $theirs = $this->adopt('beurre', 'copy', self::OTHER);

        // Then
        $this->assertSame('beurre' . self::OTHER, $theirs);
        $this->assertNotSame($mine, $theirs);
        $this->assertSame(self::OTHER, $this->ingredientRepository->findOneBySlug($theirs)->getCreatorId());
        $this->assertSame(self::USER, $this->ingredientRepository->findOneBySlug($mine)->getCreatorId());
        $this->assertCount(2, $this->aliasRepository->all());
        $this->assertCount(8, $this->conversionRepository->findAll());
    }

    public function testAPersonalIngredientOfTheSameNameIsNeverOverwritten(): void
    {
        // Given : l'utilisateur possède déjà son propre « beurre », d'un autre rayon, d'une autre unité et sans conversion
        $own = new IngredientBuilder()->isButter()->withCreator(new UserBuilder()->withId(self::USER)->build(), true)
            ->withDepartment(new DepartmentBuilder()->isBaby())
            ->withDefaultCookingUnit(new UnitBuilder()->isKilo())
            ->withDefaultPurchaseUnit(new UnitBuilder()->isKilo())
            ->withImage('mon-image.png')
            ->build();
        $this->ingredientRepository->save($own);
        $before = $own->toArray();
        $conversions = count($this->conversionRepository->findAll());

        // When
        $slug = $this->adopt('beurre');

        // Then : la copie prend le slug suivant, l'ingrédient de l'utilisateur n'a pas bougé
        $this->assertSame('beurre-2' . self::USER, $slug);
        $this->assertSame($before, $this->ingredientRepository->findOneBySlug('beurre' . self::USER)->toArray());
        $this->assertNull($this->ingredientRepository->findOneBySlug('beurre' . self::USER)->getAdoptedFrom());
        $this->assertSame('beurre', $this->ingredientRepository->findOneBySlug($slug)->getAdoptedFrom());
        $this->assertSame($slug, $this->aliasRepository->find(self::USER, 'beurre')->getIngredientSlug());
        // Et ses conversions (aucune) non plus : seules celles du commun sont copiées, vers la copie
        $this->assertCount(0, $this->conversionsOf($own));
        $this->assertCount($conversions + 2, $this->conversionRepository->findAll());
    }

    public function testTheNextFreeSlugIsTakenWhenTheFirstOnesAre(): void
    {
        // Given
        foreach (['beurre' . self::USER, 'beurre-2' . self::USER] as $slug) {
            $this->ingredientRepository->save(new IngredientBuilder()->isButter()->withSlug($slug)->withCreator(new UserBuilder()->withId(self::USER)->build())->build());
        }

        // When
        $slug = $this->adopt('beurre');

        // Then
        $this->assertSame('beurre-3' . self::USER, $slug);
    }

    public function testAPersonalIngredientCannotBeAdopted(): void
    {
        // Given
        $before = $this->snapshot();

        // Then
        $this->expectExceptionObject(IngredientException::notCommon('laitother-id'));

        // When
        try {
            $this->adopt('laitother-id');
        } finally {
            $this->assertSame($before, $this->snapshot());
        }
    }

    public function testAnUnknownIngredientIsNotFound(): void
    {
        // Then
        $this->expectExceptionObject(IngredientException::notFound('inconnu'));

        // When
        $this->adopt('inconnu');
    }

    public function testAnInvalidSourceWritesNothing(): void
    {
        // Given
        $before = $this->snapshot();

        // Then
        $this->expectException(IngredientAliasException::class);

        // When
        try {
            $this->adopt('beurre', str_repeat('s', 65));
        } finally {
            $this->assertSame($before, $this->snapshot());
        }
    }

    public function testAnAdoptionThatLostTheRaceReturnsTheIngredientOfTheWinnerAndCreatesNothing(): void
    {
        // Given : entre la première lecture de l'alias et son écriture, une autre transaction a adopté le même commun (la clé de la
        // table l'arbitre : `save()` ne trouve plus rien à écrire)
        $winner = IngredientAlias::create(self::USER, 'beurre', 'beurreuser-id', AliasKind::ADOPTION, 'first-use', new DateTimeImmutable(self::NOW));
        $racing = new class ($winner) extends IngredientAliasInMemoryRepository {
            private bool $firstRead = true;

            public function __construct(private readonly IngredientAlias $winner)
            {
                parent::__construct();
            }

            public function find(string $userId, string $aliasSlug): ?IngredientAlias
            {
                if ($this->firstRead) {
                    $this->firstRead = false;

                    return null;
                }

                return $this->winner;
            }

            public function save(IngredientAlias $alias): bool
            {
                return false;
            }
        };
        $before = $this->snapshot();

        // When
        $slug = $this->handlerWith($racing)(new AdoptCommonIngredientCommand(self::USER, 'beurre', 'copy'));

        // Then : l'ingrédient du gagnant, pas de deuxième copie ni de conversion de plus
        $this->assertSame('beurreuser-id', $slug);
        $this->assertSame($before, $this->snapshot());
    }

    private function adopt(string $commonSlug, string $source = 'copy', string $userId = self::USER): string
    {
        return ($this->handler)(new AdoptCommonIngredientCommand($userId, $commonSlug, $source));
    }

    private function handlerWith(IngredientAliasInMemoryRepository $aliasRepository): AdoptCommonIngredientHandler
    {
        return new AdoptCommonIngredientHandler(
            $this->ingredientRepository,
            $this->conversionRepository,
            $aliasRepository,
            new IngredientSlugPolicy(),
            new UuidGenerator(),
            new FrozenClock(new DateTimeImmutable(self::NOW)),
        );
    }

    /**
     * @return array<string, Conversion> les conversions PROPRES de l'ingrédient, par identifiant
     */
    private function conversionsOf(Ingredient $ingredient): array
    {
        $conversions = [];
        foreach ($this->conversionRepository->findByIngredient($ingredient) as $conversion) {
            $conversions[$conversion->getId()] = $conversion;
        }
        ksort($conversions);

        return $conversions;
    }

    /**
     * @param array<string, Conversion> $conversions
     * @return list<array{string, string, float}>
     */
    private function shape(array $conversions): array
    {
        $shape = array_map(
            static fn(Conversion $c): array => [$c->getStartUnit()->getSlug(), $c->getEndUnit()->getSlug(), $c->getCoefficient()],
            array_values($conversions),
        );
        sort($shape);

        return $shape;
    }

    /**
     * @return array{list<string>, list<string>, list<string>}
     */
    private function snapshot(): array
    {
        $ingredients = array_map(static fn(Ingredient $i): string => $i->getSlug(), array_values($this->ingredientRepository->findAll()->toArray()));
        $conversions = array_map(static fn(Conversion $c): string => $c->getId(), array_values($this->conversionRepository->findAll()->toArray()));
        $aliases = array_map(static fn(IngredientAlias $a): string => $a->getId() . '>' . $a->getIngredientSlug(), $this->aliasRepository->all());
        sort($ingredients);
        sort($conversions);
        sort($aliases);

        return [$ingredients, $conversions, $aliases];
    }
}
