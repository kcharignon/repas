<?php

namespace Repas\Tests\User\InputAdapter;


use Repas\Tests\Helper\QueryBusWebTestCase;

/**
 * L'inscription publique est plafonnée par adresse IP : 10 par heure (config/packages/rate_limiter.yaml), puis 429 avec
 * `Retry-After` (ADR 0008). L'état du limiteur est en mémoire en test : le client garde le même noyau d'une requête à l'autre.
 */
class RegisterNewUserRateLimitTest extends QueryBusWebTestCase
{
    public function testTheEleventhRegistrationFromTheSameIpIsRefused(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $this->client->disableReboot();

        // When : dix inscriptions passent
        for ($i = 1; $i <= 10; $i++) {
            $this->post("visiteur$i@example.com");
            $this->assertSame(204, $this->client->getResponse()->getStatusCode(), "inscription $i");
        }
        $this->post('visiteur11@example.com');

        // Then
        $response = $this->client->getResponse();
        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame(['error' => ['message' => 'Too Many Requests']], $this->jsonBody());
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->assertLessThanOrEqual(3600, (int) $response->headers->get('Retry-After'));
        $this->assertCount(10, $commandBus->dispatched());
    }

    public function testRequestsWithAnInvalidBodyCountToo(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $this->client->disableReboot();

        // When
        for ($i = 1; $i <= 10; $i++) {
            $this->client->request('POST', '/api/v1/users', content: '{}', server: ['CONTENT_TYPE' => 'application/json']);
            $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        }
        $this->post('visiteur@example.com');

        // Then
        $this->assertSame(429, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testOtherRoutesAreNotCounted(): void
    {
        // Given
        $this->client->disableReboot();

        // When : 15 lectures publiques, puis une inscription
        for ($i = 1; $i <= 15; $i++) {
            $this->client->request('GET', '/api/v1/health');
        }
        $commandBus = $this->mockCommandBus();
        $this->post('visiteur@example.com');

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertCount(1, $commandBus->dispatched());
    }

    public function testTheAddressesOfOneIpv6PrefixShareOneAllowance(): void
    {
        // Given : dix inscriptions depuis dix adresses du même préfixe /64
        $this->mockCommandBus();
        $this->client->disableReboot();
        for ($i = 1; $i <= 10; $i++) {
            $this->post("visiteur$i@example.com", sprintf('2001:db8:1:2::%x', $i));
            $this->assertSame(204, $this->client->getResponse()->getStatusCode(), "inscription $i");
        }

        // When : une autre adresse de ce préfixe, puis une d'un autre préfixe
        $this->post('visiteur11@example.com', '2001:db8:1:2:aaaa:bbbb:cccc:dddd');
        $this->assertSame(429, $this->client->getResponse()->getStatusCode());
        $this->post('visiteur12@example.com', '2001:db8:1:3::1');

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
    }

    private function post(string $email, string $address = '127.0.0.1'): void
    {
        $this->client->request('POST', '/api/v1/users', content: json_encode(['email' => $email], JSON_THROW_ON_ERROR), server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $address]);
    }
}
