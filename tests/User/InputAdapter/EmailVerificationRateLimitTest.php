<?php

namespace Repas\Tests\User\InputAdapter;


use Repas\Tests\Helper\QueryBusWebTestCase;

/**
 * Les deux routes publiques de confirmation d'adresse partagent un plafond par adresse IP (limiteur `email_verification`).
 */
class EmailVerificationRateLimitTest extends QueryBusWebTestCase
{
    public function testTheTwentyFirstRequestOfAnIpIsRefusedWithRetryAfter(): void
    {
        // Given
        $this->mockCommandBus();
        $this->client->disableReboot();

        // When : vingt demandes mêlant renvoi et confirmation
        for ($i = 1; $i <= 20; $i++) {
            $this->post($i % 2 === 0 ? '/api/v1/users/email-verification/send' : '/api/v1/users/email-verification/confirm', $i % 2 === 0 ? ['email' => 'a@example.com'] : ['token' => 'x']);
            $this->assertSame(204, $this->client->getResponse()->getStatusCode(), "demande $i");
        }
        $this->post('/api/v1/users/email-verification/send', ['email' => 'a@example.com']);

        // Then
        $response = $this->client->getResponse();
        $this->assertSame(429, $response->getStatusCode());
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->assertArrayHasKey('error', $this->jsonBody());
    }

    public function testAnotherIpHasItsOwnAllowance(): void
    {
        // Given
        $this->mockCommandBus();
        $this->client->disableReboot();
        for ($i = 1; $i <= 21; $i++) {
            $this->post('/api/v1/users/email-verification/send', ['email' => 'a@example.com']);
        }
        $this->assertSame(429, $this->client->getResponse()->getStatusCode());

        // When
        $this->post('/api/v1/users/email-verification/send', ['email' => 'a@example.com'], '203.0.113.9');

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
    }

    public function testTheAddressesOfOneIpv6PrefixShareOneAllowance(): void
    {
        // Given : vingt demandes depuis vingt adresses du même préfixe /64
        $this->mockCommandBus();
        $this->client->disableReboot();
        for ($i = 1; $i <= 20; $i++) {
            $this->post('/api/v1/users/email-verification/send', ['email' => 'a@example.com'], sprintf('2001:db8:1:2::%x', $i));
            $this->assertSame(204, $this->client->getResponse()->getStatusCode(), "demande $i");
        }

        // When : une autre adresse de ce préfixe, puis une d'un autre préfixe
        $this->post('/api/v1/users/email-verification/send', ['email' => 'a@example.com'], '2001:db8:1:2:aaaa:bbbb:cccc:dddd');
        $this->assertSame(429, $this->client->getResponse()->getStatusCode());
        $this->post('/api/v1/users/email-verification/send', ['email' => 'a@example.com'], '2001:db8:1:3::1');

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $path, array $body, string $ip = '198.51.100.7'): void
    {
        $this->client->request('POST', $path, content: json_encode($body, JSON_THROW_ON_ERROR), server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip]);
    }
}
