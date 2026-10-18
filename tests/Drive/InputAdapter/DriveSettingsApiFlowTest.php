<?php

namespace Repas\Tests\Drive\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Shared\Application\Interface\DriveFeature;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\ControlledDriveFeature;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Parcours des réglages du drive avec les vrais bus, la vraie base et les listeners : le jeton JWT désigne l'utilisateur,
 * le magasin enregistré par `PUT` se relit par `GET`. Les tests unitaires des controllers doublent les bus ; celui-ci
 * prouve le câblage.
 */
class DriveSettingsApiFlowTest extends DatabaseTestCase
{
    private const string URL = 'https://exemple.leclercdrive.fr/magasin-123456-123456';
    private const string OTHER_URL = 'https://autre.leclercdrive.fr/magasin-654321-654321';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
    }

    public function testAUserWithoutSettingsGetsNoStoreThenHisStoreAfterThePut(): void
    {
        // Given
        $user = $this->savedUser();

        // When
        $this->request('GET', $user);

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['enabled' => true, 'retailer' => 'leclerc', 'store' => null], $this->jsonBody());

        // When
        $this->request('PUT', $user, ['retailer' => 'leclerc', 'store' => ['id' => '123456', 'label' => 'Ville-Test', 'baseUrl' => self::URL]]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('GET', $user);

        // Then
        $this->assertSame(
            ['enabled' => true, 'retailer' => 'leclerc', 'store' => ['id' => '123456', 'label' => 'Ville-Test', 'baseUrl' => self::URL]],
            $this->jsonBody(),
        );
    }

    public function testChangingTheStoreReplacesThePreviousOne(): void
    {
        // Given
        $user = $this->savedUser();
        $this->request('PUT', $user, ['retailer' => 'leclerc', 'store' => ['id' => '123456', 'label' => 'Ville-Test', 'baseUrl' => self::URL]]);

        // When
        $this->request('PUT', $user, ['retailer' => 'leclerc', 'store' => ['id' => '654321', 'baseUrl' => self::OTHER_URL]]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('GET', $user);

        // Then : un seul magasin, sans l'ancien libellé
        $this->assertSame(['id' => '654321', 'label' => null, 'baseUrl' => self::OTHER_URL], $this->jsonBody()['store']);
        $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_settings'));
    }

    public function testAUserNeverReadsTheStoreOfAnother(): void
    {
        // Given
        $user = $this->savedUser();
        $other = $this->savedUser();
        $this->request('PUT', $user, ['retailer' => 'leclerc', 'store' => ['id' => '123456', 'baseUrl' => self::URL]]);

        // When
        $this->request('GET', $other);

        // Then
        $this->assertNull($this->jsonBody()['store']);
    }

    public function testTheOwnerOfTheStoreComesFromTheTokenNeverFromTheBody(): void
    {
        // Given : Alice a un magasin ; Mallory désigne Alice dans le corps de sa demande
        $alice = $this->savedUser();
        $mallory = $this->savedUser();
        $this->request('PUT', $alice, ['retailer' => 'leclerc', 'store' => ['id' => '123456', 'baseUrl' => self::URL]]);

        // When
        $this->request('PUT', $mallory, ['userId' => $alice->getId(), 'retailer' => 'leclerc', 'store' => ['id' => '654321', 'baseUrl' => self::OTHER_URL]]);

        // Then : le magasin d'Alice n'a pas bougé, celui de Mallory est enregistré
        $this->request('GET', $alice);
        $this->assertSame('123456', $this->jsonBody()['store']['id']);
        $this->request('GET', $mallory);
        $this->assertSame('654321', $this->jsonBody()['store']['id']);
    }

    public function testAStoreOfAnotherHostOrWithAnotherIdIsRefused(): void
    {
        // Given
        $user = $this->savedUser();

        // When : autre hôte
        $this->request('PUT', $user, ['retailer' => 'leclerc', 'store' => ['id' => '123456', 'baseUrl' => 'https://exemple.example.com/magasin-123456-123456']]);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'DRIVE_STORE_INVALID']], $this->jsonBody());

        // When : identifiant différent de celui de l'adresse
        $this->request('PUT', $user, ['retailer' => 'leclerc', 'store' => ['id' => '999999', 'baseUrl' => self::URL]]);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'DRIVE_STORE_INVALID']], $this->jsonBody());
        $this->assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_settings'));
    }

    public function testWithTheSwitchOffTheGetAnswersEnabledFalseAndThePutAnswers404(): void
    {
        // Given : le noyau est gardé entre les deux requêtes, sinon l'interrupteur posé serait perdu au redémarrage
        $this->client->disableReboot();
        static::getContainer()->set(DriveFeature::class, new ControlledDriveFeature(false));
        $user = $this->savedUser();

        // When
        $this->request('GET', $user);

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['enabled' => false, 'retailer' => 'leclerc', 'store' => null], $this->jsonBody());

        // When
        $this->request('PUT', $user, ['retailer' => 'leclerc', 'store' => ['id' => '123456', 'baseUrl' => self::URL]]);

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'DRIVE_DISABLED']], $this->jsonBody());
        $this->assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_settings'));
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function request(string $method, User $user, ?array $body = null): void
    {
        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $this->client->request($method, '/api/v1/drive/settings', content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR), server: [
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

    private function savedUser(): User
    {
        $user = new UserBuilder()->build();
        static::getContainer()->get(UserRepository::class)->save($user);

        return $user;
    }
}
