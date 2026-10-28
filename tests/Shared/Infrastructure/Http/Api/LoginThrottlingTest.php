<?php

namespace Repas\Tests\Shared\Infrastructure\Http\Api;


use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\User\Domain\Interface\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * `login_throttling` (config/packages/security.yaml, `LoginRateLimiter`) : 5 échecs par 15 minutes pour un couple adresse + email,
 * 25 pour une même adresse (préfixe /64 en IPv6), 20 puis un toutes les 3 minutes pour un même email toutes adresses confondues ;
 * au-delà, 429 avec `Retry-After`, même avec le bon mot de passe. Un autre email n'est pas touché. L'état du limiteur est en
 * mémoire en test : le client garde le même noyau d'une requête à l'autre.
 */
class LoginThrottlingTest extends DatabaseTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $this->client->disableReboot();
        $repository = static::getContainer()->get(UserRepository::class);
        $repository->save(new UserBuilder()->withEmail('alexiane@example.com')->withPassword(password_hash('test', PASSWORD_BCRYPT, ['cost' => 4]))->build());
        $repository->save(new UserBuilder()->withEmail('autre@example.com')->withPassword(password_hash('test', PASSWORD_BCRYPT, ['cost' => 4]))->build());
    }

    public function testTheSixthAttemptIsRefusedEvenWithTheRightPassword(): void
    {
        // When : cinq échecs
        for ($i = 1; $i <= 5; $i++) {
            $this->login('alexiane@example.com', 'mauvais');
            $this->assertSame(401, $this->client->getResponse()->getStatusCode(), "échec $i");
        }
        $this->login('alexiane@example.com', 'test');

        // Then
        $response = $this->client->getResponse();
        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame(['error' => ['message' => 'Too many failed login attempts, please try again later.']], $this->jsonBody());
        $this->assertGreaterThanOrEqual(60, (int) $response->headers->get('Retry-After'));
        $this->assertLessThanOrEqual(960, (int) $response->headers->get('Retry-After'));
    }

    public function testAnotherEmailIsNotAffected(): void
    {
        // Given
        for ($i = 1; $i <= 6; $i++) {
            $this->login('alexiane@example.com', 'mauvais');
        }
        $this->assertSame(429, $this->client->getResponse()->getStatusCode());

        // When
        $this->login('autre@example.com', 'test');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('token', $this->jsonBody());
    }

    public function testAnUnknownEmailIsThrottledLikeAKnownOne(): void
    {
        // When : le plafond ne révèle pas si le compte existe
        for ($i = 1; $i <= 5; $i++) {
            $this->login('inconnu@example.com', 'mauvais');
        }
        $this->login('inconnu@example.com', 'mauvais');

        // Then
        $this->assertSame(429, $this->client->getResponse()->getStatusCode());
    }

    public function testASuccessfulLoginBeforeTheLimitIsNotCounted(): void
    {
        // When : quatre échecs, une connexion réussie remet le compteur à zéro, quatre échecs de plus
        for ($i = 1; $i <= 4; $i++) {
            $this->login('alexiane@example.com', 'mauvais');
        }
        $this->login('alexiane@example.com', 'test');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        for ($i = 1; $i <= 4; $i++) {
            $this->login('alexiane@example.com', 'mauvais');
            $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        }
    }

    public function testTheSixAddressesOfOneIpv6PrefixShareTheFiveFailures(): void
    {
        // When : cinq échecs depuis cinq adresses du même préfixe /64, puis la bonne combinaison depuis une sixième
        for ($i = 1; $i <= 5; $i++) {
            $this->login('alexiane@example.com', 'mauvais', sprintf('2001:db8:1:2::%x', $i));
            $this->assertSame(401, $this->client->getResponse()->getStatusCode(), "échec $i");
        }
        $this->login('alexiane@example.com', 'test', '2001:db8:1:2:aaaa:bbbb:cccc:dddd');

        // Then : refusée ; un autre préfixe, non
        $this->assertSame(429, $this->client->getResponse()->getStatusCode());
        $this->login('alexiane@example.com', 'test', '2001:db8:1:3::1');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testAnotherAddressIsNotBlockedByTheFiveFailuresOfTheFirst(): void
    {
        // When
        for ($i = 1; $i <= 6; $i++) {
            $this->login('alexiane@example.com', 'mauvais', '203.0.113.7');
        }
        $this->assertSame(429, $this->client->getResponse()->getStatusCode());
        $this->login('alexiane@example.com', 'test', '203.0.113.8');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testTheTwentyFirstFailureOfAnEmailIsRefusedWhateverTheAddress(): void
    {
        // When : vingt échecs depuis vingt adresses, un par adresse
        for ($i = 1; $i <= 20; $i++) {
            $this->login('alexiane@example.com', 'mauvais', "203.0.113.$i");
            $this->assertSame(401, $this->client->getResponse()->getStatusCode(), "échec $i");
        }
        $this->login('alexiane@example.com', 'test', '198.51.100.1');

        // Then : même format et même en-tête que le plafond par adresse ; l'attente est de trois minutes au plus
        $response = $this->client->getResponse();
        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame(['error' => ['message' => 'Too many failed login attempts, please try again later.']], $this->jsonBody());
        $this->assertGreaterThanOrEqual(60, (int) $response->headers->get('Retry-After'));
        $this->assertLessThanOrEqual(240, (int) $response->headers->get('Retry-After'));
        $this->login('autre@example.com', 'test', '198.51.100.1');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testAnUnknownEmailIsCappedAcrossAddressesLikeAKnownOne(): void
    {
        // When
        for ($i = 1; $i <= 20; $i++) {
            $this->login('inconnu@example.com', 'mauvais', "203.0.113.$i");
        }
        $this->login('inconnu@example.com', 'mauvais', '198.51.100.1');

        // Then
        $this->assertSame(429, $this->client->getResponse()->getStatusCode());
    }

    public function testRefusedAttemptsOfOneAddressDoNotCloseTheEmailToTheOthers(): void
    {
        // When : une adresse échoue cinq fois puis insiste quarante fois
        for ($i = 1; $i <= 45; $i++) {
            $this->login('alexiane@example.com', 'mauvais', '203.0.113.7');
        }
        $this->assertSame(429, $this->client->getResponse()->getStatusCode());

        // Then : le propriétaire se connecte depuis une autre
        $this->login('alexiane@example.com', 'test', '198.51.100.1');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testAnAddressTryingTwentySixEmailsIsRefused(): void
    {
        // When
        for ($i = 1; $i <= 25; $i++) {
            $this->login("personne$i@example.com", 'mauvais', '203.0.113.7');
        }
        $this->login('alexiane@example.com', 'test', '203.0.113.7');

        // Then
        $this->assertSame(429, $this->client->getResponse()->getStatusCode());
    }

    private function login(string $email, string $password, string $address = '127.0.0.1'): void
    {
        $this->client->request('POST', '/api/login_check', content: json_encode(['email' => $email, 'password' => $password], JSON_THROW_ON_ERROR), server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $address]);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
