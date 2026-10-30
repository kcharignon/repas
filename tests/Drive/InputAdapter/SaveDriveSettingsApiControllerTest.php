<?php

namespace Repas\Tests\Drive\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Drive\Application\Command\SaveDriveSettings\SaveDriveSettingsCommand;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Shared\Application\Interface\DriveFeature;
use Repas\Tests\Helper\ControlledDriveFeature;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Domain\Model\User;

class SaveDriveSettingsApiControllerTest extends QueryBusWebTestCase
{
    private const string URL = 'https://exemple.leclercdrive.fr/magasin-123456-123456';

    public function testTheConnectedUserSavesHisStore(): void
    {
        // Given
        $user = $this->savedUser();
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($user, ['retailer' => 'leclerc', 'store' => ['id' => '123456', 'label' => 'Ville-Test', 'baseUrl' => self::URL]]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new SaveDriveSettingsCommand($user->getId(), Retailer::LECLERC, '123456', 'Ville-Test', self::URL)], $commandBus->dispatched());
    }

    public function testTheLabelIsOptional(): void
    {
        // Given
        $user = $this->savedUser();
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($user, ['retailer' => 'leclerc', 'store' => ['id' => '123456', 'baseUrl' => self::URL]]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new SaveDriveSettingsCommand($user->getId(), Retailer::LECLERC, '123456', null, self::URL)], $commandBus->dispatched());
    }

    public function testTheUserComesFromTheTokenNeverFromTheBody(): void
    {
        // Given
        $user = $this->savedUser();
        $other = $this->savedUser();
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($user, ['userId' => $other->getId(), 'retailer' => 'leclerc', 'store' => ['id' => '123456', 'baseUrl' => self::URL, 'userId' => $other->getId()]]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new SaveDriveSettingsCommand($user->getId(), Retailer::LECLERC, '123456', null, self::URL)], $commandBus->dispatched());
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
        $this->put($this->savedUser(), $body);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidBodies(): iterable
    {
        $store = ['id' => '123456', 'baseUrl' => self::URL];
        yield 'corps vide' => [[]];
        yield 'sans enseigne' => [['store' => $store]];
        yield 'enseigne inconnue' => [['retailer' => 'auchan', 'store' => $store]];
        yield 'sans magasin' => [['retailer' => 'leclerc']];
        yield 'magasin qui n\'est pas un objet' => [['retailer' => 'leclerc', 'store' => '123456']];
        yield 'sans identifiant' => [['retailer' => 'leclerc', 'store' => ['baseUrl' => self::URL]]];
        yield 'identifiant vide' => [['retailer' => 'leclerc', 'store' => ['id' => '', 'baseUrl' => self::URL]]];
        yield 'identifiant de plus de 40 caractères' => [['retailer' => 'leclerc', 'store' => ['id' => str_repeat('1', 41), 'baseUrl' => self::URL]]];
        yield 'sans adresse' => [['retailer' => 'leclerc', 'store' => ['id' => '123456']]];
        yield 'adresse vide' => [['retailer' => 'leclerc', 'store' => ['id' => '123456', 'baseUrl' => '']]];
        yield 'adresse de plus de 255 caractères' => [['retailer' => 'leclerc', 'store' => ['id' => '123456', 'baseUrl' => str_repeat('a', 256)]]];
        yield 'libellé de plus de 255 caractères' => [['retailer' => 'leclerc', 'store' => ['id' => '123456', 'baseUrl' => self::URL, 'label' => str_repeat('a', 256)]]];
        yield 'libellé de mauvais type' => [['retailer' => 'leclerc', 'store' => ['id' => '123456', 'baseUrl' => self::URL, 'label' => ['x']]]];
    }

    public function testAnswers404DriveDisabledWhenTheSwitchIsOffEvenWithAnInvalidBody(): void
    {
        // Given
        static::getContainer()->set(DriveFeature::class, new ControlledDriveFeature(false));
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($this->savedUser(), []);

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
        $this->client->request('PUT', '/api/v1/drive/settings', content: '{}', server: ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function put(User $user, array $body): void
    {
        $this->client->request('PUT', '/api/v1/drive/settings', content: json_encode($body, JSON_THROW_ON_ERROR), server: $this->bearerFor($user) + ['CONTENT_TYPE' => 'application/json']);
    }
}
