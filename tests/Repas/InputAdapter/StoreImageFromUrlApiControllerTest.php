<?php

namespace Repas\Tests\Repas\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Repas\Application\Command\StoreImageFromUrl\StoreImageFromUrlCommand;
use Repas\Repas\Domain\Exception\ImageException;
use Repas\Shared\Domain\Exception\RateLimitedException;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Domain\Model\User;

/**
 * `POST /api/v1/images/from-url` (ADR 0039), le contrôleur seul (le bus de commandes enregistre sans exécuter) : administrateur seul, le corps ne juge que sa
 * forme, l'administrateur du jeton est celui de la commande, les erreurs métier gardent leur code et leur statut. Le parcours complet est dans
 * `ImageUploadApiFlowTest`.
 */
class StoreImageFromUrlApiControllerTest extends QueryBusWebTestCase
{
    private const string PATH = 'images/uploads/3f5a0d1c9b7e4a2f8c6d1e0b9a8f7c6d5e4f3a2b1c0d9e8f7a6b5c4d3e2f1a0b.webp';
    private const string URL = 'https://exemple.org/images/surgeles.png';

    public function testAnAdministratorGetsThePathAndTheLocation(): void
    {
        // Given
        $commandBus = $this->mockCommandBus()->willReturn(StoreImageFromUrlCommand::class, self::PATH);
        $admin = $this->savedUser(admin: true);

        // When
        $this->post($admin, ['url' => self::URL]);

        // Then : la commande porte le lien reçu (jugé plus tard par le domaine) et l'administrateur du JETON
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['path' => self::PATH], $this->jsonBody());
        $this->assertSame('/' . self::PATH, $this->client->getResponse()->headers->get('Location'));
        $this->assertEquals([new StoreImageFromUrlCommand(self::URL, $admin->getId())], $commandBus->dispatched());
    }

    public function testTheUserOfTheCommandComesFromTheTokenNeverFromTheBody(): void
    {
        // Given
        $commandBus = $this->mockCommandBus()->willReturn(StoreImageFromUrlCommand::class, self::PATH);
        $admin = $this->savedUser(admin: true);

        // When
        $this->post($admin, ['url' => self::URL, 'userId' => 'un-autre-compte']);

        // Then
        $this->assertSame($admin->getId(), $commandBus->dispatched()[0]->userId);
    }

    public function testRefusesAUserWhoIsNotAnAdministrator(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->post($this->savedUser(), ['url' => self::URL]);

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('POST', '/api/v1/images/from-url', content: json_encode(['url' => self::URL]), server: ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'sans lien' => [[]];
        yield 'lien nul' => [['url' => null]];
        yield 'lien qui est un nombre' => [['url' => 22082]];
        yield 'lien qui est un booléen' => [['url' => true]];
        yield 'lien qui est une liste' => [['url' => [self::URL]]];
        yield 'lien qui est un objet' => [['url' => ['a' => 'b']]];
        yield 'lien de plus de 2 000 caractères' => [['url' => 'https://exemple.org/' . str_repeat('a', 2000)]];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testRefusesAnInvalidBody(array $body): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->post($this->savedUser(admin: true), $body);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('message', $this->jsonBody()['error']);
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesABodyThatIsNotJson(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('POST', '/api/v1/images/from-url', content: 'pas du json', server: $this->bearerFor($this->savedUser(admin: true)) + ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(400, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @return iterable<string, array{\Throwable, int, string}>
     */
    public static function failures(): iterable
    {
        yield 'lien refusé' => [ImageException::urlRefused(), 422, 'IMAGE_URL_REFUSED'];
        yield 'le site ne répond pas' => [ImageException::downloadFailed(), 502, 'IMAGE_DOWNLOAD_FAILED'];
        yield 'pas une image' => [ImageException::notAnImage(), 422, 'IMAGE_NOT_AN_IMAGE'];
        yield 'format refusé' => [ImageException::unsupported(), 422, 'IMAGE_UNSUPPORTED'];
        yield 'trop grosse' => [ImageException::tooLarge(), 413, 'IMAGE_TOO_LARGE'];
        yield 'le disque refuse' => [ImageException::storageFailed(), 500, 'IMAGE_STORAGE_FAILED'];
        yield 'le traitement échoue' => [ImageException::processingFailed(), 500, 'IMAGE_PROCESSING_FAILED'];
    }

    #[DataProvider('failures')]
    public function testAMetierFailureKeepsItsStatusAndItsCode(\Throwable $failure, int $status, string $message): void
    {
        // Given
        $this->mockCommandBus()->willThrow(StoreImageFromUrlCommand::class, $failure);

        // When
        $this->post($this->savedUser(admin: true), ['url' => self::URL]);

        // Then : un code, jamais le lien
        $this->assertSame($status, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => $message]], $this->jsonBody());
        $this->assertNull($this->client->getResponse()->headers->get('Location'));
    }

    public function testTheRateLimitHasItsRetryAfter(): void
    {
        // Given
        $this->mockCommandBus()->willThrow(StoreImageFromUrlCommand::class, RateLimitedException::after('IMAGE_DOWNLOAD_RATE_LIMITED', 321));

        // When
        $this->post($this->savedUser(admin: true), ['url' => self::URL]);

        // Then
        $this->assertSame(429, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'IMAGE_DOWNLOAD_RATE_LIMITED']], $this->jsonBody());
        $this->assertSame('321', $this->client->getResponse()->headers->get('Retry-After'));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(User $user, array $body): void
    {
        $this->client->request('POST', '/api/v1/images/from-url', content: json_encode($body, JSON_THROW_ON_ERROR), server: $this->bearerFor($user) + ['CONTENT_TYPE' => 'application/json']);
    }
}
