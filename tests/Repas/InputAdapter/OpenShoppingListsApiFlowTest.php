<?php

namespace Repas\Tests\Repas\InputAdapter;


use DateTimeImmutable;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Repas\Application\Service\ActiveListForType;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Repas\Domain\Model\ShoppingListType;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Plusieurs listes en cours, une par type (ADR 0036 décisions 6 à 8, LST-004), de bout en bout : vrais bus, vraie base, vraie sécurité.
 */
class OpenShoppingListsApiFlowTest extends DatabaseTestCase
{
    private KernelBrowser $client;
    private DataPersister $persister;
    private User $owner;
    private User $other;
    private string $butcherId;
    private string $marketId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $this->persister = new DataPersister(static::getContainer());
        $this->owner = $this->savedUser();
        $this->other = $this->savedUser();
        $this->butcherId = $this->savedType($this->owner, 'butcher', 'Boucherie', '2026-10-02 10:00:00');
        $this->marketId = $this->savedType($this->owner, 'market', 'Marché', '2026-10-01 10:00:00');
    }

    public function testAnAccountWithoutTypeKeepsTheBehaviourOfToday(): void
    {
        // Given : un compte sans type, une liste principale en cours
        $user = $this->savedUser();
        $first = $this->createdList($user);

        // When : il en crée une autre
        $second = $this->createdList($user);

        // Then : la première est en pause, la seconde en cours, et « open » ne rend que la principale
        $this->assertSame(['PAUSED', 'ACTIVE'], [$this->statusOf($first), $this->statusOf($second)]);
        $this->request('GET', '/api/v1/shopping-lists/open', $user);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame([[$second, null]], array_map(static fn(array $l): array => [$l['id'], $l['typeId']], $this->jsonBody()['member']));
        $this->assertSame(1, $this->jsonBody()['totalItems']);
        $this->request('GET', '/api/v1/shopping-lists', $user);
        $this->assertSame([null, null], array_column($this->jsonBody()['member'], 'typeId'));
    }

    public function testCreatingATypedListDoesNotPauseTheMainListAndOpenListsAreOrdered(): void
    {
        // Given
        $main = $this->createdList($this->owner);

        // When : un Marché (type plus ancien) puis une Boucherie
        $butcher = $this->createdList($this->owner, $this->butcherId);
        $market = $this->createdList($this->owner, $this->marketId);

        // Then : les trois sont en cours, nommées comme leur type ; la principale d'abord, puis les types par date de création
        $this->assertSame(['ACTIVE', 'ACTIVE', 'ACTIVE'], [$this->statusOf($main), $this->statusOf($butcher), $this->statusOf($market)]);
        $this->request('GET', '/api/v1/shopping-lists/open', $this->owner);
        $members = $this->jsonBody()['member'];
        $this->assertSame([$main, $market, $butcher], array_column($members, 'id'));
        $this->assertSame([null, $this->marketId, $this->butcherId], array_column($members, 'typeId'));
        $this->assertSame(['Marché', 'Boucherie'], [$members[1]['name'], $members[2]['name']]);
        $this->assertSame([0, 0, 1], [$members[1]['rowCount'], $members[1]['uncheckedCount'], $members[1]['version']]);
        $this->assertSame(3, $this->jsonBody()['totalItems']);
        // « Mes listes » dit le type de chacune
        $this->request('GET', '/api/v1/shopping-lists', $this->owner);
        $typeById = array_column($this->jsonBody()['member'], 'typeId', 'id');
        $expectedTypes = [$main => null, $butcher => $this->butcherId, $market => $this->marketId];
        ksort($expectedTypes);
        ksort($typeById);
        $this->assertSame($expectedTypes, $typeById);
        // Le résumé de la liste active est celui de la principale
        $this->request('GET', '/api/v1/shopping-lists/active/summary', $this->owner);
        $this->assertSame($main, $this->jsonBody()['shoppingListId']);
    }

    public function testCreatingASecondListOfATypePausesOnlyTheListOfThatType(): void
    {
        // Given
        $main = $this->createdList($this->owner);
        $butcher = $this->createdList($this->owner, $this->butcherId);
        $market = $this->createdList($this->owner, $this->marketId);

        // When
        $newButcher = $this->createdList($this->owner, $this->butcherId);

        // Then
        $this->assertSame(['ACTIVE', 'PAUSED', 'ACTIVE', 'ACTIVE'], [$this->statusOf($main), $this->statusOf($butcher), $this->statusOf($market), $this->statusOf($newButcher)]);
    }

    public function testActivatingAPausedTypedListPausesOnlyTheCurrentListOfThatType(): void
    {
        // Given : une Boucherie en pause (remplacée par une plus récente), la principale et le Marché en cours
        $main = $this->createdList($this->owner);
        $oldButcher = $this->createdList($this->owner, $this->butcherId);
        $market = $this->createdList($this->owner, $this->marketId);
        $currentButcher = $this->createdList($this->owner, $this->butcherId);

        // When
        $this->request('POST', "/api/v1/shopping-lists/$oldButcher/activate", $this->owner);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['ACTIVE', 'ACTIVE', 'ACTIVE', 'PAUSED'], [$this->statusOf($main), $this->statusOf($market), $this->statusOf($oldButcher), $this->statusOf($currentButcher)]);
    }

    public function testReopeningASentTypedListPausesOnlyTheCurrentListOfThatType(): void
    {
        // Given : une Boucherie envoyée au drive, une autre Boucherie en cours, la principale en cours
        $main = $this->createdList($this->owner);
        $sent = $this->savedList(fn(ShoppingListBuilder $b) => $b->withListTypeId($this->butcherId)->withName('Boucherie')->withStatus(ShoppingListStatus::SENT)->addIngredient(new IngredientBuilder()->isEgg()->build()));
        $current = $this->createdList($this->owner, $this->butcherId);

        // When
        $this->request('POST', "/api/v1/shopping-lists/$sent/reopen", $this->owner);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['ACTIVE', 'ACTIVE', 'PAUSED'], [$this->statusOf($main), $this->statusOf($sent), $this->statusOf($current)]);
    }

    public function testTheTypeOfAnotherUserOrAnUnknownTypeIsNotFoundAndNothingIsWritten(): void
    {
        // Given
        $main = $this->createdList($this->owner);
        $theirs = $this->savedType($this->other, 'theirs', 'Boucherie', '2026-10-02 10:00:00');
        $before = $this->listCount();

        // When / Then
        foreach ([$theirs, 'unknown-type'] as $typeId) {
            $this->request('POST', '/api/v1/shopping-lists', $this->owner, ['typeId' => $typeId]);
            $this->assertSame(404, $this->client->getResponse()->getStatusCode(), $typeId);
            $this->assertSame(['error' => ['message' => 'LIST_TYPE_NOT_FOUND']], $this->jsonBody());
        }
        $this->assertSame($before, $this->listCount());
        $this->assertSame('ACTIVE', $this->statusOf($main));
    }

    public function testOpeningOnDemandReturnsTheCurrentListOrOpensANewOneNamedLikeTheType(): void
    {
        // Given : la principale en cours, rien pour la Boucherie
        $main = $this->createdList($this->owner);
        $service = static::getContainer()->get(ActiveListForType::class);

        // When : ouvre la Boucherie, puis la redemande
        $opened = $service->forType($this->owner->getId(), $this->butcherId);
        $again = $service->forType($this->owner->getId(), $this->butcherId);

        // Then
        $this->assertSame([true, false], [$opened->opened, $again->opened]);
        $this->assertSame($opened->shoppingList->getId(), $again->shoppingList->getId());
        $this->assertSame(['Boucherie', $this->butcherId], [$opened->shoppingList->getName(), $opened->shoppingList->getListTypeId()]);
        $this->assertSame('ACTIVE', $this->statusOf($main));
    }

    public function testOpeningOnDemandOnATypeWhoseListIsSentOpensAFreshListAndNeverTouchesTheSentOne(): void
    {
        // Given : la liste Boucherie est envoyée au drive
        $sent = $this->savedList(fn(ShoppingListBuilder $b) => $b->withListTypeId($this->butcherId)->withName('Boucherie')->withStatus(ShoppingListStatus::SENT)->addIngredient(new IngredientBuilder()->isEgg()->build()));
        $before = $this->snapshot($sent);

        // When
        $opened = static::getContainer()->get(ActiveListForType::class)->forType($this->owner->getId(), $this->butcherId);

        // Then
        $this->assertTrue($opened->opened);
        $this->assertNotSame($sent, $opened->shoppingList->getId());
        $this->assertSame('ACTIVE', $this->statusOf($opened->shoppingList->getId()));
        $this->entityManager->clear();
        $this->assertSame($before, $this->snapshot($sent));
        $this->request('GET', '/api/v1/shopping-lists/open', $this->owner);
        $this->assertSame([$opened->shoppingList->getId()], array_column($this->jsonBody()['member'], 'id'));
    }

    public function testOpenIsNotReadAsAListIdentifierAndNeverShowsTheListsOfAnotherUser(): void
    {
        // Given
        $this->createdList($this->other);

        // When
        $this->request('GET', '/api/v1/shopping-lists/open', $this->owner);

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['member' => [], 'totalItems' => 0], $this->jsonBody());
    }

    public function testRefusesUnauthenticatedRequests(): void
    {
        // When
        $this->client->request('GET', '/api/v1/shopping-lists/open');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    private function savedType(User $owner, string $id, string $name, string $createdAt): string
    {
        $this->persister->saveShoppingListType(ShoppingListType::create($id, $owner->getId(), $name, new DateTimeImmutable($createdAt)));

        return $id;
    }

    private function createdList(User $user, ?string $typeId = null): string
    {
        $this->request('POST', '/api/v1/shopping-lists', $user, $typeId === null ? null : ['typeId' => $typeId]);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());

        return basename($this->client->getResponse()->headers->get('Location'));
    }

    private function statusOf(string $listId): string
    {
        $this->entityManager->clear();

        return $this->entityManager->getConnection()->fetchOne('SELECT status FROM shopping_list WHERE id = ?', [$listId]);
    }

    private function listCount(): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM shopping_list WHERE owner = ?', [$this->owner->getId()]);
    }

    /**
     * @param callable(ShoppingListBuilder): ShoppingListBuilder $configure
     */
    private function savedList(callable $configure): string
    {
        $list = $configure(new ShoppingListBuilder()->withOwner($this->owner))->build();
        static::getContainer()->get(ShoppingListRepository::class)->save($list);

        return $list->getId();
    }

    /**
     * @return array{type: ?string, status: string, version: int, name: ?string, sentAt: ?string, rows: int}
     */
    private function snapshot(string $listId): array
    {
        $connection = $this->entityManager->getConnection();
        $row = $connection->fetchAssociative('SELECT list_type_id, status, version, name, sent_at FROM shopping_list WHERE id = ?', [$listId]);

        return [
            'type' => $row['list_type_id'],
            'status' => $row['status'],
            'version' => (int) $row['version'],
            'name' => $row['name'],
            'sentAt' => $row['sent_at'],
            'rows' => (int) $connection->fetchOne('SELECT COUNT(*) FROM shopping_list_row WHERE shopping_list = ?', [$listId]),
        ];
    }

    private function savedUser(): User
    {
        $user = new UserBuilder()->build();
        static::getContainer()->get(UserRepository::class)->save($user);

        return $user;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function request(string $method, string $uri, User $user, ?array $body = null): void
    {
        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $this->client->request($method, $uri, content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR), server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
