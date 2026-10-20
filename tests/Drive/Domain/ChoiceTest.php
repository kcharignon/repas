<?php

namespace Repas\Tests\Drive\Domain;


use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Drive\Domain\Model\AcceptedProduct;
use Repas\Drive\Domain\Model\Choice;
use Repas\Drive\Domain\Model\ChoiceKind;
use Repas\Drive\Domain\Model\PackSize;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Domain\Model\SearchQuery;
use Repas\Tests\Helper\Builder\ChoiceBuilder;

class ChoiceTest extends TestCase
{
    public function testANewChoiceIsEmpty(): void
    {
        // When
        $choice = Choice::create('choice-1', 'user-1', Retailer::LECLERC, '123456', 'farine', new DateTimeImmutable('2026-10-18 10:00:00'));

        // Then
        $this->assertSame(ChoiceKind::PRODUCTS, $choice->getKind());
        $this->assertSame([], $choice->getAccepted());
        $this->assertSame([], $choice->getRejectedProductIds());
        $this->assertFalse($choice->isHaveAtHome());
    }

    #[DataProvider('invalidSlugs')]
    public function testRefusesAnInvalidIngredientSlug(string $slug): void
    {
        // Then
        $this->expectExceptionObject(DriveException::invalidChoice());

        // When
        Choice::create('choice-1', 'user-1', Retailer::LECLERC, '123456', $slug, new DateTimeImmutable());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSlugs(): iterable
    {
        yield 'vide' => [''];
        yield 'majuscule' => ['Farine'];
        yield 'espace' => ['farine de ble'];
        yield 'accent' => ['crème'];
        yield 'saut de ligne final' => ["farine\n"];
        yield 'trop long' => [str_repeat('a', 256)];
    }

    public function testThePrimaryProductTakesRankOneAndTheOthersGoDown(): void
    {
        // Given
        $choice = new ChoiceBuilder()->build();

        // When
        $choice->addPrimary('a')->addPrimary('b')->addPrimary('c');

        // Then
        $this->assertSame(['c', 'b', 'a'], $choice->getAcceptedProductIds());
        $this->assertEquals([new AcceptedProduct('c', 1), new AcceptedProduct('b', 2), new AcceptedProduct('a', 3)], $choice->getAccepted());
    }

    public function testAskingAgainForAnAcceptedProductAsPrimaryMovesItUp(): void
    {
        // Given
        $choice = new ChoiceBuilder()->withAccepted(['a', 'b', 'c'])->build();

        // When
        $choice->addPrimary('c');

        // Then : pas de doublon, rangs contigus
        $this->assertSame(['c', 'a', 'b'], $choice->getAcceptedProductIds());
    }

    public function testAFallbackProductGoesLastAndAnAcceptedOneDoesNotMove(): void
    {
        // Given
        $choice = new ChoiceBuilder()->withAccepted(['a', 'b'])->build();

        // When
        $choice->addFallback('c')->addFallback('a');

        // Then
        $this->assertSame(['a', 'b', 'c'], $choice->getAcceptedProductIds());
    }

    public function testNoMoreThanFiveAcceptedProducts(): void
    {
        // Given
        $choice = new ChoiceBuilder()->withAccepted(['a', 'b', 'c', 'd', 'e'])->build();

        // When
        foreach (['addPrimary', 'addFallback'] as $method) {
            try {
                $choice->$method('f');
                $this->fail("$method d'un sixième produit aurait dû échouer");
            } catch (DriveException $exception) {
                // Then
                $this->assertSame('DRIVE_CHOICE_INVALID', $exception->getMessage());
            }
        }
        $this->assertSame(['a', 'b', 'c', 'd', 'e'], $choice->getAcceptedProductIds(), 'un échec ne change rien');

        // When : un produit déjà accepté ne compte pas pour un sixième
        $choice->addPrimary('e')->addFallback('a');
        $this->assertSame(['e', 'a', 'b', 'c', 'd'], $choice->getAcceptedProductIds());
    }

    public function testRejectingAnAcceptedProductRemovesItAndPacksTheRanks(): void
    {
        // Given
        $choice = new ChoiceBuilder()->withAccepted(['a', 'b', 'c'])->build();

        // When
        $choice->reject('b');

        // Then
        $this->assertEquals([new AcceptedProduct('a', 1), new AcceptedProduct('c', 2)], $choice->getAccepted());
        $this->assertSame(['b'], $choice->getRejectedProductIds());
    }

    public function testRejectingTwiceKeepsASingleLine(): void
    {
        // Given
        $choice = new ChoiceBuilder()->withRejected(['x'])->build();

        // When
        $choice->reject('x');

        // Then
        $this->assertSame(['x'], $choice->getRejectedProductIds());
    }

    public function testAcceptingARejectedProductRemovesItFromTheRejectedOnes(): void
    {
        // Given
        $choice = new ChoiceBuilder()->withAccepted(['a'])->withRejected(['x', 'y'])->build();

        // When
        $choice->addFallback('x');

        // Then : jamais à la fois accepté et refusé
        $this->assertSame(['a', 'x'], $choice->getAcceptedProductIds());
        $this->assertSame(['y'], $choice->getRejectedProductIds());

        // When
        $choice->addPrimary('y');

        // Then
        $this->assertSame(['y', 'a', 'x'], $choice->getAcceptedProductIds());
        $this->assertSame([], $choice->getRejectedProductIds());
    }

    public function testNoMoreThanTwentyRejectedProducts(): void
    {
        // Given
        $rejected = array_map(static fn(int $i): string => "r$i", range(1, 20));
        $choice = new ChoiceBuilder()->withRejected($rejected)->build();

        // When : un refusé déjà connu ne compte pas pour un vingt et unième
        $choice->reject('r5');
        $this->assertCount(20, $choice->getRejectedProductIds());

        // Then
        $this->expectExceptionObject(DriveException::invalidChoice());

        // When
        $choice->reject('r21');
    }

    public function testRemoveAndUnrejectForgetAProductWithoutTouchingTheOtherList(): void
    {
        // Given
        $choice = new ChoiceBuilder()->withAccepted(['a', 'b', 'c'])->withRejected(['x', 'y'])->build();

        // When
        $choice->remove('b')->unreject('x');

        // Then
        $this->assertSame(['a', 'c'], $choice->getAcceptedProductIds());
        $this->assertSame(['y'], $choice->getRejectedProductIds());
        $this->assertSame([2], [$choice->getAccepted()[1]->rank]);
    }

    public function testReorderAcceptsAPermutationOnly(): void
    {
        // Given
        $choice = new ChoiceBuilder()->withAccepted(['a', 'b', 'c'])->build();

        // When
        $choice->reorder(['c', 'a', 'b']);

        // Then
        $this->assertSame(['c', 'a', 'b'], $choice->getAcceptedProductIds());
        foreach ([['a', 'b'], ['a', 'b', 'c', 'd'], ['a', 'a', 'b'], ['a', 'b', 'z']] as $notAPermutation) {
            try {
                $choice->reorder($notAPermutation);
                $this->fail('Un ordre qui n\'est pas une permutation aurait dû être refusé : ' . implode(',', $notAPermutation));
            } catch (DriveException $exception) {
                $this->assertSame('DRIVE_CHOICE_INVALID', $exception->getMessage());
            }
        }
        $this->assertSame(['c', 'a', 'b'], $choice->getAcceptedProductIds());
    }

    public function testHaveAtHomeErasesAllProducts(): void
    {
        // Given
        $choice = new ChoiceBuilder()->withAccepted(['a', 'b'])->withRejected(['x'])->build();

        // When
        $choice->markHaveAtHome();

        // Then
        $this->assertTrue($choice->isHaveAtHome());
        $this->assertSame([], $choice->getAcceptedProductIds());
        $this->assertSame([], $choice->getRejectedProductIds());
    }

    public function testAddingAProductAfterHaveAtHomeBuysAgain(): void
    {
        // Given
        $choice = new ChoiceBuilder()->haveAtHome()->build();

        // When
        $choice->addPrimary('a');

        // Then
        $this->assertFalse($choice->isHaveAtHome());
        $this->assertSame(['a'], $choice->getAcceptedProductIds());
    }

    public function testRejectingAProductAfterHaveAtHomeBuysAgain(): void
    {
        // Given
        $choice = new ChoiceBuilder()->haveAtHome()->build();

        // When
        $choice->reject('x');

        // Then
        $this->assertFalse($choice->isHaveAtHome());
        $this->assertSame(['x'], $choice->getRejectedProductIds());
    }

    public function testResumeBuyingLeavesAnEmptyProductsChoice(): void
    {
        // Given
        $choice = new ChoiceBuilder()->haveAtHome()->build();

        // When
        $choice->resumeBuying();

        // Then
        $this->assertSame(ChoiceKind::PRODUCTS, $choice->getKind());
        $this->assertSame([], $choice->getAcceptedProductIds());
    }

    public function testLoadOrdersTheAcceptedProductsByRank(): void
    {
        // When
        $choice = Choice::load([
            'id' => 'choice-1',
            'user_id' => 'user-1',
            'retailer' => Retailer::LECLERC,
            'store_id' => '123456',
            'ingredient_slug' => 'farine',
            'kind' => ChoiceKind::PRODUCTS,
            'accepted' => [new AcceptedProduct('b', 2), new AcceptedProduct('a', 1)],
            'rejected' => ['x'],
            'updated_at' => new DateTimeImmutable('2026-10-18 10:00:00'),
        ]);

        // Then
        $this->assertSame(['a', 'b'], $choice->getAcceptedProductIds());
        $this->assertTrue($choice->isAccepted('a'));
        $this->assertFalse($choice->isAccepted('x'));
    }

    public function testTouchSetsTheUpdateDate(): void
    {
        // Given
        $choice = new ChoiceBuilder()->build();

        // When
        $choice->touch(new DateTimeImmutable('2026-10-19 08:00:00'));

        // Then
        $this->assertEquals(new DateTimeImmutable('2026-10-19 08:00:00'), $choice->getUpdatedAt());
    }

    public function testReplaceSetsTheOrderAndTheRejectedProductsInOneGo(): void
    {
        // Given
        $choice = new ChoiceBuilder()->withAccepted(['a', 'b'])->withRejected(['x'])->build();

        // When : b remonte, a part, c est accepté, x n'est plus refusé et y l'est
        $choice->replace(false, ['b', 'c'], ['y']);

        // Then
        $this->assertEquals([new AcceptedProduct('b', 1), new AcceptedProduct('c', 2)], $choice->getAccepted());
        $this->assertSame(['y'], $choice->getRejectedProductIds());
        $this->assertFalse($choice->isHaveAtHome());
    }

    public function testReplaceWithHaveAtHomeClearsTheProductsAndBuyingResumes(): void
    {
        // Given
        $choice = new ChoiceBuilder()->withAccepted(['a'])->withRejected(['x'])->build();

        // When
        $choice->replace(true, [], []);

        // Then
        $this->assertTrue($choice->isHaveAtHome());
        $this->assertSame([[], []], [$choice->getAcceptedProductIds(), $choice->getRejectedProductIds()]);

        // When
        $choice->replace(false, ['a'], []);

        // Then
        $this->assertFalse($choice->isHaveAtHome());
        $this->assertSame(['a'], $choice->getAcceptedProductIds());
    }

    /**
     * @param list<string> $accepted
     * @param list<string> $rejected
     */
    #[DataProvider('invalidReplacements')]
    public function testReplaceRefusesAnInvalidPreferenceAndChangesNothing(bool $haveAtHome, array $accepted, array $rejected): void
    {
        // Given
        $choice = new ChoiceBuilder()->withAccepted(['a', 'b'])->withRejected(['x'])->build();

        // When
        try {
            $choice->replace($haveAtHome, $accepted, $rejected);
            $this->fail('DRIVE_CHOICE_INVALID attendu');
        } catch (DriveException $exception) {
            // Then
            $this->assertSame('DRIVE_CHOICE_INVALID', $exception->getMessage());
        }
        $this->assertSame([['a', 'b'], ['x'], false], [$choice->getAcceptedProductIds(), $choice->getRejectedProductIds(), $choice->isHaveAtHome()]);
    }

    public static function invalidReplacements(): iterable
    {
        yield 'six acceptés' => [false, ['a', 'b', 'c', 'd', 'e', 'f'], []];
        yield 'vingt et un refusés' => [false, [], array_map('strval', range(1, 21))];
        yield 'doublon accepté' => [false, ['a', 'a'], []];
        yield 'doublon refusé' => [false, [], ['x', 'x']];
        yield 'accepté et refusé' => [false, ['a'], ['a']];
        yield "j'en ai toujours avec un accepté" => [true, ['a'], []];
        yield "j'en ai toujours avec un refusé" => [true, [], ['x']];
    }

    public function testReplaceAcceptsTheLimits(): void
    {
        // When
        $choice = new ChoiceBuilder()->build()->replace(false, ['a', 'b', 'c', 'd', 'e'], array_map(static fn(int $i): string => "x$i", range(1, 20)));

        // Then
        $this->assertCount(5, $choice->getAcceptedProductIds());
        $this->assertCount(20, $choice->getRejectedProductIds());
    }

    public function testANewChoiceRemembersNoSearch(): void
    {
        // When
        $choice = Choice::create('choice-1', 'user-1', Retailer::LECLERC, '123456', 'farine', new DateTimeImmutable());

        // Then
        $this->assertNull($choice->getSearchQuery());
    }

    public function testRememberSearchSetsThenReplacesTheSearchAndForgetSearchClearsIt(): void
    {
        // Given
        $choice = new ChoiceBuilder()->withAccepted(['a'])->build();

        // When
        $choice->rememberSearch(SearchQuery::of('céréales coussins'));

        // Then
        $this->assertSame('céréales coussins', $choice->getSearchQuery()?->value);

        // When
        $choice->rememberSearch(SearchQuery::of('coussins chocolat'));

        // Then
        $this->assertSame('coussins chocolat', $choice->getSearchQuery()->value);
        $this->assertSame(['a'], $choice->getAcceptedProductIds());

        // When
        $choice->forgetSearch();

        // Then
        $this->assertNull($choice->getSearchQuery());
    }

    public function testOnlyAChoiceOfProductsRemembersASearch(): void
    {
        // Given
        $choice = new ChoiceBuilder()->haveAtHome()->build();

        // Then
        $this->expectExceptionObject(DriveException::invalidChoice());

        // When
        $choice->rememberSearch(SearchQuery::of('céréales coussins'));
    }

    public function testHavingItAtHomeForgetsTheSearch(): void
    {
        // Given
        $choice = new ChoiceBuilder()->withAccepted(['a'])->withSearchQuery('céréales coussins')->build();

        // When
        $choice->markHaveAtHome();

        // Then
        $this->assertNull($choice->getSearchQuery());

        // When : il rachète l'ingrédient
        $choice->resumeBuying();

        // Then : rien ne revient
        $this->assertNull($choice->getSearchQuery());
    }

    public function testReplaceKeepsTheSearchUnlessItIsHaveAtHome(): void
    {
        // Given
        $choice = new ChoiceBuilder()->withAccepted(['a', 'b'])->withSearchQuery('céréales coussins')->build();

        // When : l'écran de gestion réordonne les produits
        $choice->replace(false, ['b', 'a'], []);

        // Then
        $this->assertSame('céréales coussins', $choice->getSearchQuery()?->value);

        // When
        $choice->replace(true, [], []);

        // Then
        $this->assertNull($choice->getSearchQuery());
    }

    public function testAFailedReplaceKeepsTheSearch(): void
    {
        // Given
        $choice = new ChoiceBuilder()->withAccepted(['a'])->withSearchQuery('céréales coussins')->build();

        // When
        try {
            $choice->replace(true, ['a'], []);
            $this->fail('DRIVE_CHOICE_INVALID attendu');
        } catch (DriveException) {
            // Then
            $this->assertSame('céréales coussins', $choice->getSearchQuery()?->value);
        }
    }

    public function testLoadRestoresTheSearchButIgnoresOneLeftOnAHaveAtHomeChoice(): void
    {
        // When : une version plus ancienne du code a pu repasser la ligne en « j'en ai toujours » sans effacer la recherche
        $buying = new ChoiceBuilder()->withAccepted(['a'])->withSearchQuery('céréales coussins')->build();
        $atHome = new ChoiceBuilder()->haveAtHome()->withSearchQuery('céréales coussins')->build();

        // Then
        $this->assertSame('céréales coussins', $buying->getSearchQuery()?->value);
        $this->assertNull($atHome->getSearchQuery());
    }

    public function testANewChoiceRemembersNoPackSize(): void
    {
        // When
        $choice = Choice::create('choice-1', 'user-1', Retailer::LECLERC, '123456', 'cheddar', new DateTimeImmutable('2026-10-18 10:00:00'));
        $choice->addPrimary('a');

        // Then
        $this->assertNull($choice->getPackSize('a'));
        $this->assertSame([null], array_map(static fn(AcceptedProduct $product): ?PackSize => $product->packSize, $choice->getAccepted()));
    }

    public function testEachAcceptedProductHasItsOwnPackSize(): void
    {
        // Given
        $choice = new ChoiceBuilder()->withAccepted(['a', 'b', 'c'])->build();

        // When
        $choice->rememberPackSize('a', PackSize::of(20, 'tranche'))->rememberPackSize('c', PackSize::of(12, 'tranche'));

        // Then : b n'en a pas, et les rangs ne bougent pas
        $this->assertEquals(PackSize::of(20, 'tranche'), $choice->getPackSize('a'));
        $this->assertNull($choice->getPackSize('b'));
        $this->assertEquals(PackSize::of(12, 'tranche'), $choice->getPackSize('c'));
        $this->assertEquals(
            [new AcceptedProduct('a', 1, PackSize::of(20, 'tranche')), new AcceptedProduct('b', 2), new AcceptedProduct('c', 3, PackSize::of(12, 'tranche'))],
            $choice->getAccepted(),
        );

        // When : la contenance d'un produit est remplacée, puis oubliée
        $choice->rememberPackSize('a', PackSize::of(24, 'tranche'));

        // Then
        $this->assertEquals(PackSize::of(24, 'tranche'), $choice->getPackSize('a'));
        $this->assertEquals(PackSize::of(12, 'tranche'), $choice->getPackSize('c'));

        // When
        $choice->forgetPackSize('a');

        // Then
        $this->assertNull($choice->getPackSize('a'));
        $this->assertEquals(PackSize::of(12, 'tranche'), $choice->getPackSize('c'));
    }

    public function testForgettingAPackSizeThatIsNotThereChangesNothing(): void
    {
        // Given
        $choice = new ChoiceBuilder()->withAccepted(['a'])->build();

        // When
        $choice->forgetPackSize('a')->forgetPackSize('inconnu');

        // Then
        $this->assertSame(['a'], $choice->getAcceptedProductIds());
        $this->assertNull($choice->getPackSize('a'));
    }

    public function testOnlyAnAcceptedProductRemembersAPackSize(): void
    {
        // Given : b est refusé, c n'est pas dans la préférence
        $choice = new ChoiceBuilder()->withAccepted(['a'])->withRejected(['b'])->build();

        foreach (['b', 'c'] as $productId) {
            // When
            try {
                $choice->rememberPackSize($productId, PackSize::of(20, 'tranche'));
                $this->fail('DRIVE_CHOICE_INVALID attendu');
            } catch (DriveException $exception) {
                // Then
                $this->assertSame('DRIVE_CHOICE_INVALID', $exception->getMessage());
                $this->assertNull($choice->getPackSize($productId));
            }
        }
    }

    public function testAHaveAtHomeChoiceRemembersNoPackSize(): void
    {
        // Given
        $choice = new ChoiceBuilder()->haveAtHome()->build();

        // Then
        $this->expectExceptionObject(DriveException::invalidChoice());

        // When
        $choice->rememberPackSize('a', PackSize::of(20, 'tranche'));
    }

    public function testAProductThatIsNoLongerAcceptedLosesItsPackSizeAndGetsNoneBack(): void
    {
        // Given
        $choice = new ChoiceBuilder()->withAccepted(['a', 'b'])->withPackSize('a', 20, 'tranche')->withPackSize('b', 12, 'tranche')->build();

        // When : a est refusé
        $choice->reject('a');

        // Then
        $this->assertNull($choice->getPackSize('a'));
        $this->assertEquals(PackSize::of(12, 'tranche'), $choice->getPackSize('b'));

        // When : a est accepté de nouveau
        $choice->addFallback('a');

        // Then : la contenance d'avant n'est pas revenue
        $this->assertNull($choice->getPackSize('a'));
        $this->assertEquals(PackSize::of(12, 'tranche'), $choice->getPackSize('b'));

        // When : b est retiré sans être refusé, puis ajouté de nouveau
        $choice->remove('b')->addPrimary('b');

        // Then
        $this->assertNull($choice->getPackSize('b'));
    }

    public function testChangingTheOrderOrAddingAnotherProductKeepsThePackSizes(): void
    {
        // Given
        $choice = new ChoiceBuilder()->withAccepted(['a', 'b'])->withPackSize('a', 20, 'tranche')->build();

        // When : un nouveau préféré décale les autres, puis l'écran de gestion réordonne
        $choice->addPrimary('c');
        $choice->reorder(['b', 'a', 'c']);
        $choice->addFallback('a');

        // Then : chaque produit garde la sienne, le nouveau n'a jamais celle de l'ancien
        $this->assertEquals(PackSize::of(20, 'tranche'), $choice->getPackSize('a'));
        $this->assertNull($choice->getPackSize('b'));
        $this->assertNull($choice->getPackSize('c'));
    }

    public function testHavingItAtHomeForgetsThePackSizes(): void
    {
        // Given
        $choice = new ChoiceBuilder()->withAccepted(['a'])->withPackSize('a', 20, 'tranche')->build();

        // When
        $choice->markHaveAtHome()->resumeBuying()->addPrimary('a');

        // Then : rien ne revient
        $this->assertNull($choice->getPackSize('a'));
    }

    public function testReplaceKeepsThePackSizesOfTheProductsThatStayAccepted(): void
    {
        // Given
        $choice = new ChoiceBuilder()->withAccepted(['a', 'b'])->withPackSize('a', 20, 'tranche')->withPackSize('b', 12, 'tranche')->build();

        // When : l'écran de gestion inverse l'ordre, retire b, accepte c et refuse d
        $choice->replace(false, ['a', 'c'], ['d']);

        // Then
        $this->assertEquals(PackSize::of(20, 'tranche'), $choice->getPackSize('a'));
        $this->assertNull($choice->getPackSize('b'));
        $this->assertNull($choice->getPackSize('c'));
        $this->assertEquals([new AcceptedProduct('a', 1, PackSize::of(20, 'tranche')), new AcceptedProduct('c', 2)], $choice->getAccepted());

        // When
        $choice->replace(true, [], []);

        // Then
        $this->assertNull($choice->getPackSize('a'));
    }

    public function testAFailedMethodKeepsThePackSizes(): void
    {
        // Given : cinq produits acceptés dont un a une contenance
        $choice = new ChoiceBuilder()->withAccepted(['a', 'b', 'c', 'd', 'e'])->withPackSize('a', 20, 'tranche')->build();

        // When : un sixième produit est refusé par le plafond
        try {
            $choice->addFallback('f');
            $this->fail('DRIVE_CHOICE_INVALID attendu');
        } catch (DriveException) {
            // Then
            $this->assertEquals(PackSize::of(20, 'tranche'), $choice->getPackSize('a'));
        }

        // When : un remplacement invalide
        try {
            $choice->replace(true, ['a'], []);
            $this->fail('DRIVE_CHOICE_INVALID attendu');
        } catch (DriveException) {
            // Then
            $this->assertEquals(PackSize::of(20, 'tranche'), $choice->getPackSize('a'));
        }
    }

    public function testLoadRestoresThePackSizesButIgnoresOnesLeftOnAHaveAtHomeChoice(): void
    {
        // When : une ligne « j'en ai toujours » n'a aucun produit accepté ; ses contenances éventuelles sont ignorées
        $buying = new ChoiceBuilder()->withAccepted(['a', 'b'])->withPackSize('b', 20, 'tranche')->build();
        $atHome = Choice::load([
            'id' => 'choice-2', 'user_id' => 'user-1', 'retailer' => Retailer::LECLERC, 'store_id' => '123456', 'ingredient_slug' => 'cheddar',
            'kind' => ChoiceKind::HAVE_AT_HOME, 'accepted' => [new AcceptedProduct('a', 1, PackSize::of(20, 'tranche'))], 'rejected' => [],
            'updated_at' => new DateTimeImmutable('2026-10-18 10:00:00'),
        ]);

        // Then
        $this->assertNull($buying->getPackSize('a'));
        $this->assertEquals(PackSize::of(20, 'tranche'), $buying->getPackSize('b'));
        $this->assertNull($atHome->getPackSize('a'));
    }
}
