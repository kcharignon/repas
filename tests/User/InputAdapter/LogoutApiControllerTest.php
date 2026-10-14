<?php

namespace Repas\Tests\User\InputAdapter;


use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Application\RevokeRefreshToken\RevokeRefreshTokenCommand;
use Repas\User\Domain\Model\RefreshToken;

class LogoutApiControllerTest extends QueryBusWebTestCase
{
    public function testItRevokesThePresentedTokenAndClearsTheCookie(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('repas_refresh', 'the-secret', null, '/api/auth'));

        // When
        $this->client->request('POST', '/api/auth/logout');

        // Then
        $response = $this->client->getResponse();
        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
        $this->assertEquals([new RevokeRefreshTokenCommand(RefreshToken::hash('the-secret'))], $commandBus->dispatched());
        $cookies = $response->headers->getCookies();
        $this->assertCount(1, $cookies);
        $this->assertSame(['repas_refresh', '', '/api/auth'], [$cookies[0]->getName(), (string) $cookies[0]->getValue(), $cookies[0]->getPath()]);
        $this->assertLessThan(time(), $cookies[0]->getExpiresTime());
    }

    public function testWithoutCookieItStillAnswersNoContent(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('POST', '/api/auth/logout');

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testNoJwtIsRequired(): void
    {
        // Given : un jeton d'accès expiré ne doit pas empêcher de se déconnecter
        $this->mockCommandBus();

        // When
        $this->client->request('POST', '/api/auth/logout', server: ['HTTP_AUTHORIZATION' => 'Bearer not.a.valid.jwt']);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
    }
}
