<?php

namespace Repas\Tests\User\InputAdapter;


use DateTimeImmutable;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Application\RecordUserLogin\RecordUserLoginCommand;
use Repas\User\Application\RotateRefreshToken\RotateRefreshTokenCommand;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\RefreshToken;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Le controller seulement (bus de commandes simulé) : lecture du cookie, commande construite, JWT et cookie renvoyés, refus.
 * Le parcours complet avec les vrais bus est dans `RefreshTokenFlowTest`.
 */
class RefreshTokenApiControllerTest extends QueryBusWebTestCase
{
    public function testItExchangesTheCookieForAJwtAndANewCookie(): void
    {
        // Given
        $user = $this->savedUser();
        $commandBus = $this->mockCommandBus()->willReturn(RotateRefreshTokenCommand::class, $user->getEmail());

        // When
        $this->refresh('the-secret');

        // Then : un JWT valide pour le propriétaire du jeton
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $payload = static::getContainer()->get(JWTTokenManagerInterface::class)->parse($this->jsonBody()['token']);
        $this->assertSame($user->getEmail(), $payload['username']);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        // Then : la commande porte les hachages, jamais les secrets
        $command = $commandBus->dispatched()[0];
        $this->assertInstanceOf(RotateRefreshTokenCommand::class, $command);
        $newCookie = $this->refreshCookie();
        $this->assertSame(RefreshToken::hash('the-secret'), $command->presentedTokenHash);
        $this->assertSame(RefreshToken::hash($newCookie->getValue()), $command->nextTokenHash);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $newCookie->getValue());
        $this->assertNotSame('the-secret', $newCookie->getValue());
        $this->assertStringNotContainsString($newCookie->getValue(), $response->getContent());
    }

    public function testTheNewCookieIsHttpOnlyStrictAndLimitedToTheAuthPath(): void
    {
        // Given
        $this->mockCommandBus()->willReturn(RotateRefreshTokenCommand::class, $this->savedUser()->getEmail());

        // When
        $this->refresh('the-secret');

        // Then
        $cookie = $this->refreshCookie();
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame(Cookie::SAMESITE_STRICT, $cookie->getSameSite());
        $this->assertSame('/api/auth', $cookie->getPath());
        $this->assertNull($cookie->getDomain());
        $this->assertEqualsWithDelta(time() + 30 * 86400, $cookie->getExpiresTime(), 5);
    }

    public function testASuccessfulExchangeRecordsTheLastLoginOfTheAccount(): void
    {
        // Given : un compte dont la dernière connexion est inconnue
        $user = $this->savedUser();
        $commandBus = $this->mockCommandBus()->willReturn(RotateRefreshTokenCommand::class, $user->getEmail());

        // When
        $this->refresh('the-secret');

        // Then : après l'échange, une commande dédiée avec l'identifiant du compte du jeton
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertEquals(
            [RotateRefreshTokenCommand::class, RecordUserLoginCommand::class],
            array_map(static fn(object $command): string => $command::class, $commandBus->dispatched()),
        );
        $this->assertEquals(new RecordUserLoginCommand($user->getId()), $commandBus->dispatched()[1]);
    }

    public function testAnExchangeWithinTenMinutesOfTheLastLoginRecordsNothing(): void
    {
        // Given : une connexion enregistrée il y a trois minutes
        $user = $this->savedUser();
        static::getContainer()->get(UserRepository::class)->recordLogin($user->getId(), new DateTimeImmutable('-3 minutes'));
        $this->entityManager->clear(); // la requête qui suit lit la base, pas l'entité déjà chargée par le test
        $commandBus = $this->mockCommandBus()->willReturn(RotateRefreshTokenCommand::class, $user->getEmail());

        // When
        $this->refresh('the-secret');

        // Then : l'échange réussit, et rien d'autre n'est envoyé au bus (zéro requête de plus)
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertCount(1, $commandBus->dispatched());
        $this->assertInstanceOf(RotateRefreshTokenCommand::class, $commandBus->dispatched()[0]);
    }

    public function testAFailureToRecordTheLoginDoesNotRefuseTheExchange(): void
    {
        // Given : le jeton est consommé, l'enregistrement de la connexion échoue
        $user = $this->savedUser();
        $failingBus = new class($user->getEmail()) implements CommandBusInterface {
            public function __construct(private readonly string $email)
            {
            }

            public function dispatch(object $command): mixed
            {
                return $command instanceof RotateRefreshTokenCommand ? $this->email : throw new \RuntimeException('base indisponible');
            }
        };
        static::getContainer()->set(CommandBusInterface::class, $failingBus);

        // When
        $this->refresh('the-secret');

        // Then : le navigateur reçoit quand même le JWT et le cookie suivant, sinon sa session serait coupée pour une simple date
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('token', $this->jsonBody());
        $this->assertNotNull($this->refreshCookie()->getValue());
    }

    public function testARefusedTokenGivesAnUnauthorizedAnswerAndClearsTheCookie(): void
    {
        // Given : le handler refuse (null)
        $commandBus = $this->mockCommandBus();

        // When
        $this->refresh('dead-secret');

        // Then : refus, et aucune connexion enregistrée
        $this->assertCount(1, $commandBus->dispatched());
        $this->assertInstanceOf(RotateRefreshTokenCommand::class, $commandBus->dispatched()[0]);
        $response = $this->client->getResponse();
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(['error' => ['message' => 'Invalid refresh token.']], $this->jsonBody());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertClearedCookie();
    }

    public function testWithoutCookieNothingIsDispatched(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('POST', '/api/auth/refresh');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'Invalid refresh token.']], $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
        $this->assertClearedCookie();
    }

    public function testAnExpiredBearerDoesNotPreventTheExchange(): void
    {
        // Given : le front envoie encore son ancien jeton d'accès
        $this->mockCommandBus()->willReturn(RotateRefreshTokenCommand::class, $this->savedUser()->getEmail());

        // When
        $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('repas_refresh', 'the-secret', null, '/api/auth'));
        $this->client->request('POST', '/api/auth/refresh', server: ['HTTP_AUTHORIZATION' => 'Bearer not.a.valid.jwt']);

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testOnlyPostIsAllowed(): void
    {
        // When
        $this->client->request('GET', '/api/auth/refresh');

        // Then
        $this->assertSame(405, $this->client->getResponse()->getStatusCode());
    }

    private function refresh(string $secret): void
    {
        $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('repas_refresh', $secret, null, '/api/auth'));
        $this->client->request('POST', '/api/auth/refresh');
    }

    private function refreshCookie(): Cookie
    {
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'repas_refresh') {
                return $cookie;
            }
        }

        $this->fail('Le cookie repas_refresh n\'est pas dans la réponse.');
    }

    private function assertClearedCookie(): void
    {
        $cookie = $this->refreshCookie();
        $this->assertSame('', (string) $cookie->getValue());
        $this->assertLessThan(time(), $cookie->getExpiresTime());
        $this->assertSame('/api/auth', $cookie->getPath());
        $this->assertTrue($cookie->isHttpOnly());
    }
}
