<?php

namespace Repas\Tests\Repas\Domain;


use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\ShoppingList;
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

    /**
     * @return iterable<string, array{callable(ShoppingList, Recipe): mixed}>
     */
    public static function mutators(): iterable
    {
        yield 'addMeal' => [static fn(ShoppingList $l, Recipe $r) => $l->addMeal('meal-id', $r, 4)];
        yield 'removeMeal' => [static fn(ShoppingList $l, Recipe $r) => $l->removeMeal($r)];
        yield 'addRow' => [static fn(ShoppingList $l) => $l->addRow('oeuf', 'unite', 2)];
        yield 'subtractRow' => [static fn(ShoppingList $l) => $l->subtractRow('oeuf', 1)];
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
