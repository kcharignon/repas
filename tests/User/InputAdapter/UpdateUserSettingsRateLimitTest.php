<?php

namespace Repas\Tests\User\InputAdapter;


use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Le mot de passe actuel, exigé pour en changer (`PUT /api/v1/me/settings`), se refuse au plus 5 fois par 15 minutes et PAR UTILISATEUR
 * (limiteur `password_check`, config/packages/rate_limiter.yaml), puis `429` avec `Retry-After`. Un mot de passe juste ne consomme rien.
 * Vrais bus et vraie base ; l'état du limiteur est en mémoire en test, le client garde le même noyau d'une requête à l'autre.
 */
class UpdateUserSettingsRateLimitTest extends DatabaseTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $this->client->disableReboot();
    }

    public function testTheSixthRefusedCurrentPasswordIsAnsweredWithTooManyRequests(): void
    {
        // Given
        $this->savedUser('alexiane@example.com');
        $token = $this->tokenFor('alexiane@example.com', 'test');

        // When : cinq mots de passe refusés, puis le bon
        for ($i = 1; $i <= 5; $i++) {
            $this->changePassword($token, 'pas-le-bon');
            $this->assertSame(422, $this->client->getResponse()->getStatusCode(), "refus $i");
        }
        $this->changePassword($token, 'test');

        // Then : rien n'est comparé, le mot de passe est inchangé
        $response = $this->client->getResponse();
        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame(['error' => ['message' => 'PASSWORD_RATE_LIMITED']], $this->jsonBody());
        $this->assertGreaterThanOrEqual(1, (int) $response->headers->get('Retry-After'));
        $this->assertLessThanOrEqual(900, (int) $response->headers->get('Retry-After'));
        $this->assertTrue($this->reloaded('alexiane@example.com')->passwordMatch('test'));
    }

    public function testARightCurrentPasswordConsumesNothing(): void
    {
        // Given : deux refus, puis un changement réussi (qui révoque les jetons déjà émis)
        $this->savedUser('alexiane@example.com');
        $token = $this->tokenFor('alexiane@example.com', 'test');
        for ($i = 1; $i <= 2; $i++) {
            $this->changePassword($token, 'pas-le-bon');
        }
        $this->changePassword($token, 'test');
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $token = $this->tokenFor('alexiane@example.com', 'un-nouveau-mot-de-passe');

        // When : trois refus de plus
        for ($i = 1; $i <= 3; $i++) {
            $this->changePassword($token, 'pas-le-bon', 'encore-un-autre-mot-de-passe');
            $this->assertSame(422, $this->client->getResponse()->getStatusCode(), "refus $i");
        }

        // Then : cinq refus en tout, le suivant est refusé même avec le bon mot de passe
        $this->changePassword($token, 'un-nouveau-mot-de-passe', 'encore-un-autre-mot-de-passe');
        $this->assertSame(429, $this->client->getResponse()->getStatusCode());
    }

    public function testChangingTheServingAloneIsNeverCounted(): void
    {
        // Given
        $this->savedUser('alexiane@example.com');
        $token = $this->tokenFor('alexiane@example.com', 'test');

        // When : dix enregistrements du nombre de personnes
        for ($i = 1; $i <= 10; $i++) {
            $this->put($token, ['defaultServing' => $i]);
            $this->assertSame(204, $this->client->getResponse()->getStatusCode(), "enregistrement $i");
        }

        // Then : les cinq refus sont encore permis
        for ($i = 1; $i <= 5; $i++) {
            $this->changePassword($token, 'pas-le-bon');
            $this->assertSame(422, $this->client->getResponse()->getStatusCode(), "refus $i");
        }
    }

    public function testEachUserHasHisOwnCount(): void
    {
        // Given : un utilisateur a atteint son plafond
        $this->savedUser('alexiane@example.com');
        $this->savedUser('autre@example.com');
        $alexiane = $this->tokenFor('alexiane@example.com', 'test');
        $autre = $this->tokenFor('autre@example.com', 'test');
        for ($i = 1; $i <= 6; $i++) {
            $this->changePassword($alexiane, 'pas-le-bon');
        }
        $this->assertSame(429, $this->client->getResponse()->getStatusCode());

        // When
        $this->changePassword($autre, 'test');

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
    }

    private function changePassword(string $token, string $actualPassword, string $newPassword = 'un-nouveau-mot-de-passe'): void
    {
        $this->put($token, ['defaultServing' => 4, 'newPassword' => $newPassword, 'actualPassword' => $actualPassword]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function put(string $token, array $body): void
    {
        $this->client->request('PUT', '/api/v1/me/settings', content: json_encode($body, JSON_THROW_ON_ERROR), server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
    }

    private function tokenFor(string $email, string $password): string
    {
        $this->client->request('POST', '/api/login_check', content: json_encode(['email' => $email, 'password' => $password], JSON_THROW_ON_ERROR), server: ['CONTENT_TYPE' => 'application/json']);

        return $this->jsonBody()['token'];
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function savedUser(string $email): User
    {
        $user = new UserBuilder()->withEmail($email)->build();
        static::getContainer()->get(UserRepository::class)->save($user);

        return $user;
    }

    private function reloaded(string $email): User
    {
        return static::getContainer()->get(UserRepository::class)->findOneByEmail($email);
    }
}
