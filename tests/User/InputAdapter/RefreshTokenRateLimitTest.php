<?php

namespace Repas\Tests\User\InputAdapter;


use Repas\Tests\Helper\QueryBusWebTestCase;

/**
 * Le rafraîchissement est plafonné par adresse IP : 60 par 10 minutes (config/packages/rate_limiter.yaml), puis 429 avec
 * `Retry-After` (ADR 0015 §9). L'état du limiteur est en mémoire en test : le client garde le même noyau d'une requête à l'autre.
 */
class RefreshTokenRateLimitTest extends QueryBusWebTestCase
{
    public function testTheSixtyFirstRefreshFromTheSameIpIsRefused(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $this->client->disableReboot();

        // When : soixante échanges (refusés faute de cookie, ils comptent quand même)
        for ($i = 1; $i <= 60; $i++) {
            $this->client->request('POST', '/api/auth/refresh');
            $this->assertSame(401, $this->client->getResponse()->getStatusCode(), "échange $i");
        }
        $this->client->request('POST', '/api/auth/refresh');

        // Then
        $response = $this->client->getResponse();
        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame(['error' => ['message' => 'Too Many Requests']], $this->jsonBody());
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->assertLessThanOrEqual(600, (int) $response->headers->get('Retry-After'));
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testTheAddressesOfOneIpv6PrefixShareOneAllowance(): void
    {
        // Given : soixante échanges depuis soixante adresses du même préfixe /64
        $this->mockCommandBus();
        $this->client->disableReboot();
        for ($i = 1; $i <= 60; $i++) {
            $this->client->request('POST', '/api/auth/refresh', server: ['REMOTE_ADDR' => sprintf('2001:db8:1:2::%x', $i)]);
            $this->assertSame(401, $this->client->getResponse()->getStatusCode(), "échange $i");
        }

        // When : une autre adresse de ce préfixe, puis une d'un autre préfixe
        $this->client->request('POST', '/api/auth/refresh', server: ['REMOTE_ADDR' => '2001:db8:1:2:aaaa:bbbb:cccc:dddd']);
        $this->assertSame(429, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', '/api/auth/refresh', server: ['REMOTE_ADDR' => '2001:db8:1:3::1']);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testLogoutIsNotCounted(): void
    {
        // Given
        $this->mockCommandBus();
        $this->client->disableReboot();

        // When : 70 déconnexions, puis un échange
        for ($i = 1; $i <= 70; $i++) {
            $this->client->request('POST', '/api/auth/logout');
        }
        $this->client->request('POST', '/api/auth/refresh');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
    }
}
