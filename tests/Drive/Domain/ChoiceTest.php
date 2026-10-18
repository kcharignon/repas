<?php

namespace Repas\Tests\Drive\Domain;


use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Drive\Domain\Model\AcceptedProduct;
use Repas\Drive\Domain\Model\Choice;
use Repas\Drive\Domain\Model\ChoiceKind;
use Repas\Drive\Domain\Model\Retailer;
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
}
