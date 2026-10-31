<?php

namespace Repas\Tests\Repas\Domain;


use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Voice\Domain\Model\RemovedLine;
use Repas\Repas\Domain\Model\ShoppingListStatus as Status;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\User\Domain\Model\User;

/**
 * Liste envoyée au drive (ADR 0023) : statut `SENT`, transitions et verrou de l'agrégat.
 */
class ShoppingListTest extends TestCase
{
    private const string LIST_ID = 'shopping-list-id';

    private function owner(): User
    {
        return new UserBuilder()->withId('owner-id')->build();
    }

    private function recipe(): Recipe
    {
        return new RecipeBuilder()->withAuthor($this->owner())->isPastaCarbonara()->build();
    }

    /** Liste d'une recette planifiée et d'un ingrédient (une ligne). */
    private function activeList(Status $status = Status::ACTIVE): ShoppingList
    {
        return new ShoppingListBuilder()
            ->withId(self::LIST_ID)
            ->withOwner($this->owner())
            ->addRecipe($this->recipe())
            ->addIngredient(new IngredientBuilder()->isEgg())
            ->withStatus($status)
            ->build();
    }

    private function sentList(): ShoppingList
    {
        $list = $this->activeList();
        $list->send(new DateTimeImmutable('2026-10-18 10:00:00'));

        return $list;
    }

    public function testSendMakesTheActiveListSentAndKeepsTheDate(): void
    {
        // Given
        $list = $this->activeList();
        $now = new DateTimeImmutable('2026-10-18 10:00:00');

        // When
        $list->send($now);

        // Then
        $this->assertSame(Status::SENT, $list->getStatus());
        $this->assertTrue($list->isSent());
        $this->assertSame($now, $list->getSentAt());
        $this->assertFalse($list->isActive());
    }

    public function testSendingAnEmptyListIsRefused(): void
    {
        // Given
        $list = new ShoppingListBuilder()->withId(self::LIST_ID)->withOwner($this->owner())->build();

        // Then
        $this->expectExceptionObject(ShoppingListException::empty(self::LIST_ID));

        // When
        $list->send(new DateTimeImmutable());
    }

    public function testAnEmptyListErrorIsAnUnprocessableCode(): void
    {
        $exception = ShoppingListException::empty(self::LIST_ID);

        $this->assertSame([ShoppingListException::EMPTY, 422], [$exception->getMessage(), $exception->getCode()]);
    }

    #[DataProvider('notSendableStatuses')]
    public function testOnlyAnActiveListCanBeSent(Status $status): void
    {
        // Given
        $list = $this->activeList($status);

        // Then
        $this->expectExceptionObject(ShoppingListException::notSendable(self::LIST_ID));

        // When
        $list->send(new DateTimeImmutable());
    }

    /**
     * @return iterable<string, array{Status}>
     */
    public static function notSendableStatuses(): iterable
    {
        yield 'en pause' => [Status::PAUSED];
        yield 'terminée' => [Status::COMPLETED];
    }

    public function testSendingASentListAgainChangesNothing(): void
    {
        // Given
        $list = $this->sentList();
        $sentAt = $list->getSentAt();

        // When
        $list->send(new DateTimeImmutable('2026-10-19 10:00:00'));

        // Then
        $this->assertTrue($list->isSent());
        $this->assertSame($sentAt, $list->getSentAt());
    }

    public function testReopenMakesTheSentListActiveAndClearsTheDate(): void
    {
        // Given
        $list = $this->sentList();

        // When
        $list->reopen();

        // Then
        $this->assertSame(Status::ACTIVE, $list->getStatus());
        $this->assertNull($list->getSentAt());
    }

    #[DataProvider('notSentStatuses')]
    public function testOnlyASentListCanBeReopened(Status $status): void
    {
        // Given
        $list = $this->activeList($status);

        // Then
        $this->expectExceptionObject(ShoppingListException::notSent(self::LIST_ID));

        // When
        $list->reopen();
    }

    /**
     * @return iterable<string, array{Status}>
     */
    public static function notSentStatuses(): iterable
    {
        yield 'active' => [Status::ACTIVE];
        yield 'en pause' => [Status::PAUSED];
        yield 'terminée' => [Status::COMPLETED];
    }

    public function testASentListCanBeCompleted(): void
    {
        // Given
        $list = $this->sentList();

        // When
        $list->completed();

        // Then
        $this->assertSame(Status::COMPLETED, $list->getStatus());
    }

    public function testACompletedListKeepsItsSentDateButNoLongerOnceActiveAgain(): void
    {
        // Given : envoyée puis terminée, comme le relevé d'une commande
        $list = $this->sentList();
        $list->completed();
        $this->assertNotNull($list->getSentAt());

        // When : un décochage ou une activation la remet en liste active
        $list->activated();

        // Then : « date d'envoi, null si la liste n'est pas envoyée » (documentation OpenAPI)
        $this->assertSame([Status::ACTIVE, false, null], [$list->getStatus(), $list->isSent(), $list->getSentAt()]);
    }

    /**
     * @return iterable<string, array{callable(ShoppingList, Recipe): mixed}>
     */
    public static function mutators(): iterable
    {
        yield 'addMeal' => [static fn(ShoppingList $l, Recipe $r) => $l->addMeal('meal-id', $r, 4)];
        yield 'removeMeal' => [static fn(ShoppingList $l, Recipe $r) => $l->removeMeal($r)];
        yield 'addRow' => [static fn(ShoppingList $l) => $l->addRow('oeuf', 'unite', 2)];
        yield 'subtractRow' => [static fn(ShoppingList $l) => $l->subtractRow('oeuf', 1)];
        yield 'removeQuantity' => [static fn(ShoppingList $l) => $l->removeQuantity('oeuf', 1)];
        yield 'addIngredient' => [static fn(ShoppingList $l) => $l->addIngredient('oeuf', 'unite')];
        yield 'removeIngredient' => [static fn(ShoppingList $l) => $l->removeIngredient('oeuf', 'unite')];
        yield 'setName' => [static fn(ShoppingList $l) => $l->setName('Courses')];
        yield 'pause' => [static fn(ShoppingList $l) => $l->pause()];
        yield 'activated' => [static fn(ShoppingList $l) => $l->activated()];
    }

    /**
     * @param callable(ShoppingList, Recipe): mixed $mutate
     */
    #[DataProvider('mutators')]
    public function testEveryMutatorRefusesASentList(callable $mutate): void
    {
        // Given
        $list = $this->sentList();
        $before = $list->toArray();

        // When
        try {
            $mutate($list, $this->recipe());
            $this->fail('Une liste envoyée ne doit pas être modifiable');
        } catch (ShoppingListException $e) {
            // Then
            $this->assertEquals(ShoppingListException::sent(self::LIST_ID), $e);
            $this->assertSame([ShoppingListException::SENT, 409], [$e->getMessage(), $e->getCode()]);
            $this->assertSame($before, $list->toArray());
        }
    }

    public function testAnActiveListStaysEditable(): void
    {
        // Given
        $list = $this->activeList();

        // When
        $list->setName('Courses');
        $list->addIngredient('oeuf', 'unite');
        $list->pause();

        // Then
        $this->assertSame('Courses', $list->getName());
        $this->assertSame(Status::PAUSED, $list->getStatus());
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Retirer une quantité de la ligne d'un ingrédient (le retrait dit à voix haute, ADR 0029 décision 12)
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    /** Une liste active où l'œuf a une ligne de `$row` et une part ajoutée à la main de `$manual`. */
    private function listWithEgg(float $row, float $manual): ShoppingList
    {
        $list = new ShoppingListBuilder()->withId(self::LIST_ID)->withOwner($this->owner())->build();
        $list->addRow('oeuf', 'unite', $row);
        if ($manual > 0) {
            $list->addIngredient('oeuf', 'unite', $manual);
        }

        return $list;
    }

    public function testRemoveQuantityLowersTheLineAndTheHandAddedPartAndSaysWhatIsLeft(): void
    {
        // Given
        $list = $this->listWithEgg(5, 5);

        // When
        $left = $list->removeQuantity('oeuf', 2);

        // Then
        $this->assertSame(3.0, $left);
        $this->assertSame(3.0, $list->findRow('oeuf')->getQuantity());
        $this->assertSame(3.0, $list->getIngredients()->reset()->getQuantity());
    }

    public function testRemoveQuantityNeverLowersTheHandAddedPartBelowZero(): void
    {
        // Given : 5 œufs dont 2 ajoutés à la main (3 viennent d'une recette)
        $list = $this->listWithEgg(5, 2);

        // When
        $left = $list->removeQuantity('oeuf', 3);

        // Then
        $this->assertSame(2.0, $left);
        $this->assertCount(0, $list->getIngredients());
    }

    public function testRemoveQuantityDropsTheLineAndEveryHandAddedPartWhenTheLineIsUsedUp(): void
    {
        // Given : deux parts ajoutées à la main dans deux unités, une seule ligne
        $list = $this->listWithEgg(3, 3);
        $list->addIngredient('oeuf', 'gramme', 1);

        // When
        $left = $list->removeQuantity('oeuf', 3);

        // Then : plus de ligne, plus aucune part pour cet ingrédient
        $this->assertSame(0.0, $left);
        $this->assertNull($list->findRow('oeuf'));
        $this->assertCount(0, $list->getIngredients());
    }

    public function testRemoveQuantityLeavesTheOtherIngredientsAlone(): void
    {
        // Given
        $list = $this->listWithEgg(2, 2);
        $list->addRow('beurre', 'unite', 1);
        $list->addIngredient('beurre', 'unite', 1);

        // When
        $list->removeQuantity('oeuf', 5);

        // Then
        $this->assertNull($list->findRow('oeuf'));
        $this->assertSame(1.0, $list->findRow('beurre')->getQuantity());
        $this->assertCount(1, $list->getIngredients());
    }

    public function testRemoveQuantityOfAnIngredientWithoutALineChangesNothing(): void
    {
        // Given
        $list = $this->listWithEgg(2, 2);
        $before = $list->toArray();

        // When
        $left = $list->removeQuantity('beurre', 1);

        // Then
        $this->assertNull($left);
        $this->assertSame($before, $list->toArray());
    }

    public function testSubtractRowDropsTheLineLeftWithFloatingPointDust(): void
    {
        // Given : 0,1 + 0,2 puis 0,3 retiré laisse 5,5e-17, pas 0
        $list = $this->listWithEgg(0.1, 0);
        $list->addRow('oeuf', 'unite', 0.2);

        // When
        $list->subtractRow('oeuf', 0.3);

        // Then : la ligne a disparu au lieu de rester à une quantité invisible
        $this->assertNull($list->findRow('oeuf'));
    }

    public function testSubtractRowKeepsAnySmallButRealQuantity(): void
    {
        // Given : 4 g de safran, exprimés en kilos
        $list = $this->listWithEgg(0.005, 0);

        // When
        $list->subtractRow('oeuf', 0.001);

        // Then
        $this->assertEqualsWithDelta(0.004, $list->findRow('oeuf')?->getQuantity(), 1e-12);
    }

    public function testRemoveQuantityIgnoresFloatingPointDust(): void
    {
        // Given : 0,1 + 0,2 n'est pas tout à fait 0,3
        $list = $this->listWithEgg(0.1, 0);
        $list->addRow('oeuf', 'unite', 0.2);

        // When
        $left = $list->removeQuantity('oeuf', 0.3);

        // Then : la ligne est épuisée, pas de reste de 0,00000000000000005
        $this->assertSame(0.0, $left);
        $this->assertNull($list->findRow('oeuf'));
    }

    /**
     * @return iterable<string, array{float, bool}> ce qui reste après le retrait, et si la ligne est épuisée
     */
    public static function leftovers(): iterable
    {
        yield 'zéro' => [0.0, true];
        yield 'un millième' => [0.001, true];
        yield 'quatre millièmes (Alexa dirait « il t\'en reste 0 »)' => [0.004, true];
        yield 'juste sous le seuil' => [0.0049, true];
        yield 'juste au-dessus du seuil : « 0,01 » à voix haute' => [0.0051, false];
        yield 'six millièmes' => [0.006, false];
        yield 'un centième' => [0.01, false];
        yield 'un demi' => [0.5, false];
    }

    #[DataProvider('leftovers')]
    public function testALeftoverBelowTheNegligibleQuantityMeansTheLineIsUsedUp(float $leftover, bool $usedUp): void
    {
        // Given : une ligne de 2 litres, dont on retire de quoi laisser `$leftover`
        $list = $this->listWithEgg(2.0, 0);

        // When
        $left = $list->removeQuantity('oeuf', 2.0 - $leftover);

        // Then : épuisée, ou un reste qu'Alexa sait dire (jamais « 0 »)
        $this->assertSame($usedUp, $left === 0.0);
        $this->assertSame($usedUp, $list->findRow('oeuf') === null);
    }

    public function testTheNegligibleQuantityIsTheOneTheAnswerUsesToSayTheLineIsGone(): void
    {
        // Then : le domaine supprime la ligne et la réponse vocale la dit épuisée au même seuil (`RemovedLine::isGone`)
        $this->assertSame(constant(ShoppingList::class . '::NEGLIGIBLE_QUANTITY'), constant(RemovedLine::class . '::NEGLIGIBLE_QUANTITY'));
    }

    public function testASentListKeepsItsSentDateWhenLoaded(): void
    {
        // Given
        $sentAt = new DateTimeImmutable('2026-10-18 10:00:00');

        // When
        $list = new ShoppingListBuilder()->withOwner($this->owner())->withStatus(Status::SENT)->withSentAt($sentAt)->build();

        // Then
        $this->assertSame([true, $sentAt], [$list->isSent(), $list->getSentAt()]);
    }
}
