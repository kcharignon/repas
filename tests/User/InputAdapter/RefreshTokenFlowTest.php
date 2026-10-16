<?php

namespace Repas\Tests\User\InputAdapter;


use Doctrine\DBAL\Connection;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\User\Domain\Interface\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;

/**
 * Parcours du refresh token (ADR 0015) avec les vrais bus, la vraie base et le vrai cookie : connexion, échange,
 * rotation, rejeu, déconnexion, changement de mot de passe. Le client de test garde son cookie d'une requête à l'autre,
 * comme un navigateur.
 */
class RefreshTokenFlowTest extends DatabaseTestCase
{
    private const string EMAIL = 'alexiane@example.com';
    private const string PASSWORD = 'un-mot-de-passe';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $this->client->request('POST', '/api/v1/users', content: json_encode(['email' => self::EMAIL, 'password' => self::PASSWORD, 'defaultServing' => 3], JSON_THROW_ON_ERROR), server: ['CONTENT_TYPE' => 'application/json']);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());

        // Ce parcours porte sur le refresh token, pas sur la confirmation d'adresse (EmailVerificationFlowTest) : le compte est activé
        $userRepository = static::getContainer()->get(UserRepository::class);
        $user = $userRepository->findOneByEmail(self::EMAIL);
        $user->verifyEmail();
        $userRepository->save($user);
    }

    public function testLoggingInPutsTheRefreshCookieInTheBrowser(): void
    {
        // When
        $jwt = $this->login();

        // Then : le cookie est posé avec ses protections, le secret n'est pas dans le corps
        $cookie = $this->client->getCookieJar()->get('repas_refresh', '/api/auth');
        $this->assertNotNull($cookie);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $cookie->getValue());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('strict', strtolower((string) $cookie->getSameSite()));
        $this->assertSame(['token'], array_keys($this->jsonBody()));
        $this->assertNotSame($jwt, $cookie->getValue());
        $this->assertSame(1, $this->tokenCount());
    }

    public function testTheCookieIsExchangedForANewJwtAndANewCookie(): void
    {
        // Given
        $this->login();
        $firstSecret = $this->secret();

        // When
        $this->refresh();

        // Then : un JWT utilisable et un cookie renouvelé
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['email' => self::EMAIL], array_intersect_key($this->me($this->jsonBody()['token']), ['email' => true]));
        $this->assertNotSame($firstSecret, $this->secret());
        $this->assertSame(2, $this->tokenCount(), 'le jeton utilisé reste pour détecter un rejeu');
    }

    public function testTheSameConnectionCanRefreshManyTimes(): void
    {
        // Given
        $this->login();

        // When & Then
        for ($i = 1; $i <= 4; $i++) {
            $this->ageUsedTokens();
            $this->refresh();
            $this->assertSame(200, $this->client->getResponse()->getStatusCode(), "échange $i");
        }
    }

    public function testReplayingAnOldCookieRevokesTheWholeConnection(): void
    {
        // Given : le voleur a copié le cookie de départ, la victime s'en est servie depuis
        $this->login();
        $stolen = $this->secret();
        $this->refresh();
        $victim = $this->secret();
        $this->ageUsedTokens();

        // When : le voleur présente le jeton déjà utilisé
        $this->setSecret($stolen);
        $this->refresh();

        // Then : refus, cookie effacé, connexion de la victime coupée elle aussi
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'Invalid refresh token.']], $this->jsonBody());
        $this->assertSame(0, $this->tokenCount());
        $this->setSecret($victim);
        $this->refresh();
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testAReplayRightAfterTheExchangeIsRefusedWithoutRevokingTheConnection(): void
    {
        // Given : deux onglets envoient le même cookie à quelques secondes d'écart
        $this->login();
        $sharedSecret = $this->secret();
        $this->refresh();
        $successor = $this->secret();

        // When : le deuxième onglet arrive avec l'ancien cookie
        $this->setSecret($sharedSecret);
        $this->refresh();

        // Then : il est refusé, mais le cookie que le navigateur a reçu entre-temps fonctionne
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->setSecret($successor);
        $this->refresh();
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testLoggingOutRevokesTheConnectionAndTheCookieIsGone(): void
    {
        // Given
        $this->login();
        $secret = $this->secret();

        // When
        $this->client->request('POST', '/api/auth/logout');

        // Then : le navigateur n'a plus de cookie, et l'ancien secret ne sert plus
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertNull($this->client->getCookieJar()->get('repas_refresh', '/api/auth'));
        $this->assertSame(0, $this->tokenCount());
        $this->setSecret($secret);
        $this->refresh();
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testLoggingOutOneDeviceKeepsTheOthers(): void
    {
        // Given : deux connexions
        $this->login();
        $phone = $this->secret();
        $this->client->getCookieJar()->clear();
        $this->login();
        $laptop = $this->secret();

        // When : le portable se déconnecte
        $this->client->request('POST', '/api/auth/logout');

        // Then : le téléphone peut toujours rafraîchir
        $this->setSecret($phone);
        $this->refresh();
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->setSecret($laptop);
        $this->refresh();
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testChangingThePasswordEndsEveryConnection(): void
    {
        // Given : deux appareils connectés
        $jwt = $this->login();
        $phone = $this->secret();
        $this->client->getCookieJar()->clear();
        $this->login();

        // When : le mot de passe change
        $this->client->request('PUT', '/api/v1/me/settings', content: json_encode(['defaultServing' => 3, 'newPassword' => 'un-nouveau-mot-de-passe', 'actualPassword' => self::PASSWORD], JSON_THROW_ON_ERROR), server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // Then : aucun des deux cookies ne rafraîchit plus
        $this->refresh();
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->setSecret($phone);
        $this->refresh();
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->tokenCount());
    }

    public function testANewLoginAfterARevocationWorks(): void
    {
        // Given
        $this->login();
        $this->client->request('POST', '/api/auth/logout');

        // When
        $this->login();
        $this->refresh();

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testAFailedLoginPutsNoCookie(): void
    {
        // When
        $this->client->request('POST', '/api/login_check', content: json_encode(['email' => self::EMAIL, 'password' => 'faux'], JSON_THROW_ON_ERROR), server: ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertNull($this->client->getCookieJar()->get('repas_refresh', '/api/auth'));
        $this->assertSame(0, $this->tokenCount());
    }

    public function testThePurgeCommandRemovesTheExpiredTokens(): void
    {
        // Given : un jeton qui a expiré hier
        $this->login();
        $this->connection()->executeStatement("UPDATE refresh_token SET expires_at = NOW() - INTERVAL '1 day'");

        // When
        $deleted = static::getContainer()->get(\Repas\Shared\Application\Interface\CommandBusInterface::class)->dispatch(new \Repas\User\Application\PurgeExpiredRefreshTokens\PurgeExpiredRefreshTokensCommand());

        // Then
        $this->assertSame(1, $deleted);
        $this->assertSame(0, $this->tokenCount());
    }

    private function login(): string
    {
        $this->client->request('POST', '/api/login_check', content: json_encode(['email' => self::EMAIL, 'password' => self::PASSWORD], JSON_THROW_ON_ERROR), server: ['CONTENT_TYPE' => 'application/json']);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        return $this->jsonBody()['token'];
    }

    private function refresh(): void
    {
        $this->client->request('POST', '/api/auth/refresh');
    }

    private function secret(): string
    {
        return $this->client->getCookieJar()->get('repas_refresh', '/api/auth')->getValue();
    }

    private function setSecret(string $secret): void
    {
        $this->client->getCookieJar()->set(new Cookie('repas_refresh', $secret, null, '/api/auth', 'localhost'));
    }

    /**
     * @return array<string, mixed>
     */
    private function me(string $jwt): array
    {
        $this->client->request('GET', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        return $this->jsonBody();
    }

    /**
     * Fait remonter l'usage des jetons consommés au-delà du délai de grâce (le test ne peut pas attendre 10 secondes).
     */
    private function ageUsedTokens(): void
    {
        $this->connection()->executeStatement("UPDATE refresh_token SET used_at = used_at - INTERVAL '1 minute' WHERE used_at IS NOT NULL");
    }

    private function tokenCount(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM refresh_token');
    }

    private function connection(): Connection
    {
        return static::getContainer()->get('doctrine')->getConnection();
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
