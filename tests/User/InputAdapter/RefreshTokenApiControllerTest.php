<?php

namespace Repas\Tests\User\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Application\RotateRefreshToken\RotateRefreshTokenCommand;
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

    public function testARefusedTokenGivesAnUnauthorizedAnswerAndClearsTheCookie(): void
    {
        // Given : le handler refuse (null)
        $this->mockCommandBus();

        // When
        $this->refresh('dead-secret');

        // Then
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
