<?php

namespace Repas\Tests\User\InputAdapter;


use Repas\Tests\Helper\MockCommandBus;
use Repas\Tests\Helper\QueryBusWebTestCase;

/**
 * Les trois routes publiques qui font partir un email (inscription, renvoi du lien de confirmation, mot de passe oublié) partagent un
 * plafond global de 100 par heure, toutes adresses confondues (limiteur `public_email`, config/packages/rate_limiter.yaml), puis 429 avec
 * `Retry-After`. L'état du limiteur est en mémoire en test : le client garde le même noyau d'une requête à l'autre.
 */
class PublicEmailRateLimitTest extends QueryBusWebTestCase
{
    private MockCommandBus $commandBus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->commandBus = $this->mockCommandBus();
        $this->client->disableReboot();
    }

    public function testTheHundredAndFirstRequestOfTheHourIsRefusedOnEachRouteWhateverTheAddress(): void
    {
        // When : cent demandes mêlant les trois routes, chacune depuis une adresse différente
        for ($i = 1; $i <= 100; $i++) {
            match ($i % 3) {
                0 => $this->register("visiteur$i@example.com", $this->address($i)),
                1 => $this->post('/api/v1/users/email-verification/send', ['email' => "visiteur$i@example.com"], $this->address($i)),
                2 => $this->post('/api/v1/users/password-reset/send', ['email' => "visiteur$i@example.com"], $this->address($i)),
            };
            $this->assertContains($this->client->getResponse()->getStatusCode(), [201, 204], "demande $i");
        }

        // Then : la suivante est refusée, sur chacune des trois routes, avec une attente d'une heure au plus
        $this->register('visiteur101@example.com', $this->address(101));
        $this->assertTooManyRequests();
        $this->post('/api/v1/users/email-verification/send', ['email' => 'visiteur102@example.com'], $this->address(102));
        $this->assertTooManyRequests();
        $this->post('/api/v1/users/password-reset/send', ['email' => 'visiteur103@example.com'], $this->address(103));
        $this->assertTooManyRequests();
        $this->assertCount(100, $this->commandBus->dispatched());
    }

    public function testRequestsThatSendNothingAreNotCounted(): void
    {
        // When : cent vingt confirmations par lien et connexions, puis une demande d'email
        for ($i = 1; $i <= 120; $i++) {
            $this->post($i % 2 === 0 ? '/api/v1/users/email-verification/confirm' : '/api/v1/users/password-reset/confirm', ['token' => 'x', 'newPassword' => 'un-mot-de-passe'], $this->address($i));
        }
        $this->post('/api/v1/users/password-reset/send', ['email' => 'visiteur@example.com'], $this->address(121));

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
    }

    public function testRequestsWithAnInvalidBodyAreNotCounted(): void
    {
        // When : cent vingt corps invalides, puis une demande valide
        for ($i = 1; $i <= 120; $i++) {
            $this->post('/api/v1/users/password-reset/send', ['email' => 'pas-un-email'], $this->address($i));
            $this->assertSame(422, $this->client->getResponse()->getStatusCode(), "demande $i");
        }
        $this->post('/api/v1/users/password-reset/send', ['email' => 'visiteur@example.com'], $this->address(121));

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
    }

    public function testARequestRefusedByTheLimiterOfItsAddressDoesNotConsumeTheGlobalOne(): void
    {
        // Given : une adresse envoie 25 demandes, dont 5 refusées par son propre plafond (20 par heure)
        for ($i = 1; $i <= 25; $i++) {
            $this->post('/api/v1/users/password-reset/send', ['email' => 'visiteur@example.com'], '198.51.100.7');
        }
        $this->assertSame(429, $this->client->getResponse()->getStatusCode());

        // When : quatre-vingts demandes d'autres adresses
        for ($i = 1; $i <= 80; $i++) {
            $this->post('/api/v1/users/password-reset/send', ['email' => 'visiteur@example.com'], $this->address($i));
            $this->assertSame(204, $this->client->getResponse()->getStatusCode(), "demande $i");
        }

        // Then : cent demandes comptées en tout (20 + 80), la suivante est refusée
        $this->post('/api/v1/users/password-reset/send', ['email' => 'visiteur@example.com'], $this->address(81));
        $this->assertTooManyRequests();
    }

    private function assertTooManyRequests(): void
    {
        $response = $this->client->getResponse();
        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame(['error' => ['message' => 'Too Many Requests']], $this->jsonBody());
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->assertLessThanOrEqual(3600, (int) $response->headers->get('Retry-After'));
    }

    private function address(int $i): string
    {
        return sprintf('203.0.%d.%d', intdiv($i, 200), $i % 200 + 1);
    }

    private function register(string $email, string $address): void
    {
        $this->post('/api/v1/users', ['email' => $email, 'password' => 'un-mot-de-passe', 'defaultServing' => 4], $address);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $path, array $body, string $address): void
    {
        $this->client->request('POST', $path, content: json_encode($body, JSON_THROW_ON_ERROR), server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $address]);
    }
}
