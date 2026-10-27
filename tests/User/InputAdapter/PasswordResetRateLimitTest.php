<?php

namespace Repas\Tests\User\InputAdapter;


use Repas\Tests\Helper\QueryBusWebTestCase;

/**
 * Les deux routes publiques du mot de passe oublié partagent un plafond par adresse IP (limiteur `password_reset`), distinct
 * de celui de la confirmation d'adresse (`email_verification`).
 */
class PasswordResetRateLimitTest extends QueryBusWebTestCase
{
    private int $sent = 0;

    public function testTheTwentyFirstRequestOfAnIpIsRefusedWithRetryAfter(): void
    {
        // Given
        $this->mockCommandBus();
        $this->client->disableReboot();

        // When : vingt demandes mêlant demande du lien et réinitialisation
        for ($i = 1; $i <= 20; $i++) {
            $this->post(
                $i % 2 === 0 ? '/api/v1/users/password-reset/send' : '/api/v1/users/password-reset/confirm',
                $i % 2 === 0 ? ['email' => $this->email()] : ['token' => 'x', 'password' => 'un-mot-de-passe'],
            );
            $this->assertSame(204, $this->client->getResponse()->getStatusCode(), "demande $i");
        }
        $this->post('/api/v1/users/password-reset/send', ['email' => $this->email()]);

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
            $this->post('/api/v1/users/password-reset/send', ['email' => $this->email()]);
        }
        $this->assertSame(429, $this->client->getResponse()->getStatusCode());

        // When
        $this->post('/api/v1/users/password-reset/send', ['email' => $this->email()], '203.0.113.9');

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
    }

    public function testTheAddressConfirmationLimiterIsNotConsumed(): void
    {
        // Given : le plafond du mot de passe oublié est atteint
        $this->mockCommandBus();
        $this->client->disableReboot();
        for ($i = 1; $i <= 21; $i++) {
            $this->post('/api/v1/users/password-reset/send', ['email' => $this->email()]);
        }
        $this->assertSame(429, $this->client->getResponse()->getStatusCode());

        // When : la même adresse IP confirme son adresse email
        $this->post('/api/v1/users/email-verification/confirm', ['token' => 'x']);

        // Then : l'autre plafond est intact
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
    }

    public function testTheAddressesOfOneIpv6PrefixShareOneAllowance(): void
    {
        // Given : vingt demandes depuis vingt adresses du même préfixe /64
        $this->mockCommandBus();
        $this->client->disableReboot();
        for ($i = 1; $i <= 20; $i++) {
            $this->post('/api/v1/users/password-reset/send', ['email' => $this->email()], sprintf('2001:db8:1:2::%x', $i));
            $this->assertSame(204, $this->client->getResponse()->getStatusCode(), "demande $i");
        }

        // When : une autre adresse de ce préfixe, puis une d'un autre préfixe
        $this->post('/api/v1/users/password-reset/send', ['email' => $this->email()], '2001:db8:1:2:aaaa:bbbb:cccc:dddd');
        $this->assertSame(429, $this->client->getResponse()->getStatusCode());
        $this->post('/api/v1/users/password-reset/send', ['email' => $this->email()], '2001:db8:1:3::1');

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Une adresse différente à chaque demande : le plafond par destinataire (6 par heure) n'est pas ce que ces tests mesurent.
     */
    private function email(): string
    {
        return 'visiteur' . ++$this->sent . '@example.com';
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $path, array $body, string $ip = '198.51.100.7'): void
    {
        $this->client->request('POST', $path, content: json_encode($body, JSON_THROW_ON_ERROR), server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip]);
    }
}
