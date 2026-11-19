<?php

namespace Repas\Tests\Drive\InputAdapter;

use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Drive\Application\Command\MarkLinesInBasket\MarkLinesInBasketCommand;
use Repas\Drive\Application\Command\MarkLinesInBasket\NotAddedLine;
use Repas\Drive\Domain\Interface\DriveFeature;
use Repas\Drive\Domain\Model\NotAddedOutcome;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Tests\Helper\ControlledDriveFeature;
use Repas\Tests\Helper\QueryBusWebTestCase;

class MarkLinesInBasketApiControllerTest extends QueryBusWebTestCase
{
    public function testTheConnectedUserMarksTheLinesOfHisSentListInTheBasket(): void
    {
        // Given
        $user = $this->savedUser();
        $list = $this->savedShoppingList($user, ShoppingListStatus::SENT);
        $commandBus = $this->mockCommandBus();

        // When
        $this->post($user, $list->getId(), ['ingredientSlugs' => ['lait', 'oeuf']]);

        // Then : l'utilisateur vient du jeton, la liste de l'adresse, les slugs du corps
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame('', $this->client->getResponse()->getContent());
        $this->assertEquals([new MarkLinesInBasketCommand($user->getId(), $list->getId(), ['lait', 'oeuf'])], $commandBus->dispatched());
    }

    public function testTheLinesThatAreNotInTheBasketAreSentWithTheirOutcomeAndReason(): void
    {
        // Given (ADR 0041)
        $user = $this->savedUser();
        $list = $this->savedShoppingList($user, ShoppingListStatus::SENT);
        $commandBus = $this->mockCommandBus();

        // When : un article au panier, un refusé par le drive, un en erreur avec sa raison
        $this->post($user, $list->getId(), [
            'ingredientSlugs' => ['oeuf'],
            'notAdded' => [
                ['ingredientSlug' => 'lait', 'outcome' => 'NOT_IN_CART'],
                ['ingredientSlug' => 'sel', 'outcome' => 'FAILED', 'reason' => 'BLOCKED'],
            ],
        ]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new MarkLinesInBasketCommand($user->getId(), $list->getId(), ['oeuf'], [
            new NotAddedLine('lait', NotAddedOutcome::NOT_IN_CART),
            new NotAddedLine('sel', NotAddedOutcome::FAILED, 'BLOCKED'),
        ])], $commandBus->dispatched());
    }

    public function testAPassageWhereNothingWentInTheBasketIsAccepted(): void
    {
        // Given
        $user = $this->savedUser();
        $list = $this->savedShoppingList($user, ShoppingListStatus::SENT);
        $commandBus = $this->mockCommandBus();

        // When : aucun article au panier, seulement des issues
        $this->post($user, $list->getId(), ['ingredientSlugs' => [], 'notAdded' => [['ingredientSlug' => 'lait', 'outcome' => 'UNAVAILABLE']]]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new MarkLinesInBasketCommand($user->getId(), $list->getId(), [], [new NotAddedLine('lait', NotAddedOutcome::UNAVAILABLE)])], $commandBus->dispatched());

        // When : et la clé `ingredientSlugs` peut même manquer
        $this->post($user, $list->getId(), ['notAdded' => [['ingredientSlug' => 'lait', 'outcome' => 'HAVE_AT_HOME']]]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
    }

    public function testTheUserOfTheBodyIsNeverTrusted(): void
    {
        // Given
        $user = $this->savedUser();
        $list = $this->savedShoppingList($user, ShoppingListStatus::SENT);
        $commandBus = $this->mockCommandBus();

        // When
        $this->post($user, $list->getId(), ['ingredientSlugs' => ['lait'], 'userId' => 'autre', 'shoppingListId' => 'autre']);

        // Then
        $this->assertEquals([new MarkLinesInBasketCommand($user->getId(), $list->getId(), ['lait'])], $commandBus->dispatched());
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testRefusesAnInvalidBody(array $body): void
    {
        // Given
        $user = $this->savedUser();
        $list = $this->savedShoppingList($user, ShoppingListStatus::SENT);
        $commandBus = $this->mockCommandBus();

        // When
        $this->post($user, $list->getId(), $body);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'sans la clé' => [[]];
        yield 'liste vide' => [['ingredientSlugs' => []]];
        yield 'pas une liste' => [['ingredientSlugs' => 'lait']];
        yield 'un doublon' => [['ingredientSlugs' => ['lait', 'lait']]];
        yield 'un slug en majuscules' => [['ingredientSlugs' => ['Lait']]];
        yield 'un slug avec une espace' => [['ingredientSlugs' => ['lait entier']]];
        yield 'un slug vide' => [['ingredientSlugs' => ['']]];
        yield 'un slug trop long' => [['ingredientSlugs' => [str_repeat('a', 256)]]];
        yield 'un nombre' => [['ingredientSlugs' => [12]]];
        yield 'un objet' => [['ingredientSlugs' => [['lait']]]];
        yield 'ni articles ni issues' => [['ingredientSlugs' => [], 'notAdded' => []]];
        yield 'issues seules mais vides' => [['notAdded' => []]];
        yield 'une issue inconnue' => [['ingredientSlugs' => ['lait'], 'notAdded' => [['ingredientSlug' => 'sel', 'outcome' => 'TOO_BAD']]]];
        yield 'au panier n\'est pas une issue de refus' => [['ingredientSlugs' => ['lait'], 'notAdded' => [['ingredientSlug' => 'sel', 'outcome' => 'IN_CART']]]];
        yield 'une issue sans ingrédient' => [['notAdded' => [['outcome' => 'FAILED']]]];
        yield 'une issue sans issue' => [['notAdded' => [['ingredientSlug' => 'sel']]]];
        yield 'une raison en minuscules' => [['notAdded' => [['ingredientSlug' => 'sel', 'outcome' => 'FAILED', 'reason' => 'blocked']]]];
        yield 'une raison qui est une phrase' => [['notAdded' => [['ingredientSlug' => 'sel', 'outcome' => 'FAILED', 'reason' => 'Le site a refusé']]]];
        yield 'une raison trop longue' => [['notAdded' => [['ingredientSlug' => 'sel', 'outcome' => 'FAILED', 'reason' => 'A' . str_repeat('B', 40)]]]];
        yield 'un slug d\'issue en majuscules' => [['notAdded' => [['ingredientSlug' => 'Sel', 'outcome' => 'FAILED']]]];
        yield 'issues qui ne sont pas une liste' => [['notAdded' => 'FAILED']];
        yield 'plus de 1 000 issues' => [['notAdded' => array_map(static fn(int $n): array => ['ingredientSlug' => "ingredient-$n", 'outcome' => 'SKIPPED'], range(1, 1001))]];
        yield 'plus de 1 000 slugs' => [['ingredientSlugs' => array_map(static fn(int $n): string => "ingredient-$n", range(1, 1001))]];
    }

    public function testRefusesABodyThatIsNotJson(): void
    {
        // Given
        $user = $this->savedUser();
        $list = $this->savedShoppingList($user, ShoppingListStatus::SENT);
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('POST', '/api/v1/drive/plans/' . $list->getId() . '/basket-lines', content: 'pas du json', server: $this->bearerFor($user) + ['CONTENT_TYPE' => 'application/json']);

        // Then : un corps qui n'est pas du JSON est une requête mal formée (400, comme pour toute route à corps JSON), jamais une commande
        $this->assertSame(400, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAListOfAnotherUser(): void
    {
        // Given
        $list = $this->savedShoppingList($this->savedUser(), ShoppingListStatus::SENT);
        $commandBus = $this->mockCommandBus();

        // When
        $this->post($this->savedUser(), $list->getId(), ['ingredientSlugs' => ['lait']]);

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testAnUnknownListIsNotFound(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->post($this->savedUser(), 'liste-inconnue', ['ingredientSlugs' => ['lait']]);

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRelaysTheConflictOfAListThatIsNotSent(): void
    {
        // Given
        $user = $this->savedUser();
        $list = $this->savedShoppingList($user);
        $this->mockCommandBus()->willThrow(MarkLinesInBasketCommand::class, ShoppingListException::notSent($list->getId()));

        // When
        $this->post($user, $list->getId(), ['ingredientSlugs' => ['lait']]);

        // Then
        $this->assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'SHOPPING_LIST_NOT_SENT']], $this->jsonBody());
    }

    public function testRelaysTheRefusalOfTheOwnerCheckedByTheUseCase(): void
    {
        // Given : un administrateur passe le voter, le cas d'usage refuse la liste d'un autre
        $admin = $this->savedUser(true);
        $list = $this->savedShoppingList($this->savedUser(), ShoppingListStatus::SENT);
        $this->mockCommandBus()->willThrow(MarkLinesInBasketCommand::class, ShoppingListException::forbidden($list->getId()));

        // When
        $this->post($admin, $list->getId(), ['ingredientSlugs' => ['lait']]);

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'SHOPPING_LIST_FORBIDDEN']], $this->jsonBody());
    }

    public function testAnswers404DriveDisabledWhenTheSwitchIsOff(): void
    {
        // Given
        static::getContainer()->set(DriveFeature::class, new ControlledDriveFeature(false));
        $user = $this->savedUser();
        $list = $this->savedShoppingList($user, ShoppingListStatus::SENT);
        $commandBus = $this->mockCommandBus();

        // When
        $this->post($user, $list->getId(), ['ingredientSlugs' => ['lait']]);

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'DRIVE_DISABLED']], $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('POST', '/api/v1/drive/plans/list-1/basket-lines', content: '{"ingredientSlugs":["lait"]}', server: ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(\Repas\User\Domain\Model\User $user, string $listId, array $body): void
    {
        $this->client->request('POST', "/api/v1/drive/plans/$listId/basket-lines", content: json_encode($body, JSON_THROW_ON_ERROR), server: $this->bearerFor($user) + ['CONTENT_TYPE' => 'application/json']);
    }
}
