<?php

namespace Repas\Tests\User\InputAdapter;


use Repas\Tests\Helper\MockCommandBus;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Infrastructure\Http\EmailRecipientPayload;
use Repas\User\Infrastructure\Http\PublicEmailRateLimitListener;

/**
 * Les trois routes publiques qui font partir un email (inscription, renvoi du lien de confirmation, mot de passe oublié) partagent un
 * plafond global de 100 par heure, toutes adresses confondues (limiteur `public_email`), et un plafond de 6 par heure pour une même
 * adresse saisie (limiteur `public_email_recipient`, config/packages/rate_limiter.yaml), puis 429 avec `Retry-After`. L'état des limiteurs
 * est en mémoire en test : le client garde le même noyau d'une requête à l'autre.
 */
class PublicEmailRateLimitTest extends QueryBusWebTestCase
{
    private MockCommandBus $commandBus;
    private int $sent = 0;

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
            $this->assertSame(204, $this->client->getResponse()->getStatusCode(), "demande $i");
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
            $this->post('/api/v1/users/password-reset/send', ['email' => $this->email()], '198.51.100.7');
        }
        $this->assertSame(429, $this->client->getResponse()->getStatusCode());

        // When : quatre-vingts demandes d'autres adresses
        for ($i = 1; $i <= 80; $i++) {
            $this->post('/api/v1/users/password-reset/send', ['email' => $this->email()], $this->address($i));
            $this->assertSame(204, $this->client->getResponse()->getStatusCode(), "demande $i");
        }

        // Then : cent demandes comptées en tout (20 + 80), la suivante est refusée
        $this->post('/api/v1/users/password-reset/send', ['email' => $this->email()], $this->address(81));
        $this->assertTooManyRequests();
    }

    public function testAnAddressIsLimitedToSixRequestsAnHourWhateverTheRouteTheIpAndTheSpelling(): void
    {
        // When : six demandes pour la même adresse, écrite de façons différentes, sur les trois routes, depuis six adresses IP
        $spellings = ['visiteur@example.com', 'Visiteur@Example.com', 'VISITEUR@EXAMPLE.COM', 'visiteur@EXAMPLE.com', 'visiteur@example.com', 'VisiTeur@example.com'];
        foreach ($spellings as $i => $email) {
            match ($i % 3) {
                0 => $this->register($email, $this->address($i + 1)),
                1 => $this->post('/api/v1/users/email-verification/send', ['email' => $email], $this->address($i + 1)),
                2 => $this->post('/api/v1/users/password-reset/send', ['email' => $email], $this->address($i + 1)),
            };
            $this->assertSame(204, $this->client->getResponse()->getStatusCode(), "demande $i");
        }

        // Then : la septième est refusée, quelle que soit la route ou l'adresse IP
        $this->post('/api/v1/users/password-reset/send', ['email' => 'visiteur@example.com'], $this->address(7));
        $this->assertTooManyRequests();
        $this->post('/api/v1/users/email-verification/send', ['email' => 'VISITEUR@example.com'], $this->address(8));
        $this->assertTooManyRequests();
        $this->register('visiteur@example.com', $this->address(9));
        $this->assertTooManyRequests();
        $this->assertCount(6, $this->commandBus->dispatched());
    }

    public function testTheTagOfTheLocalPartDoesNotGiveAnotherCounter(): void
    {
        // When : six demandes pour la même boîte, avec et sans étiquette `+x`
        $spellings = ['visiteur@example.com', 'visiteur+a@example.com', 'visiteur+b@example.com', 'Visiteur+Courses@Example.com', 'visiteur+a+b@example.com', 'visiteur@example.com'];
        foreach ($spellings as $i => $email) {
            $this->post('/api/v1/users/password-reset/send', ['email' => $email], $this->address($i + 1));
            $this->assertSame(204, $this->client->getResponse()->getStatusCode(), "demande $i");
        }

        // Then : la septième est refusée, étiquette ou non ; une autre boîte n'est pas touchée
        $this->post('/api/v1/users/password-reset/send', ['email' => 'visiteur+z@example.com'], $this->address(7));
        $this->assertTooManyRequests();
        $this->post('/api/v1/users/password-reset/send', ['email' => 'visiteur2+z@example.com'], $this->address(8));
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
    }

    public function testAnotherAddressIsNotAffectedByTheSixRequestsOfTheFirst(): void
    {
        // Given : une adresse a atteint son plafond
        for ($i = 1; $i <= 7; $i++) {
            $this->post('/api/v1/users/password-reset/send', ['email' => 'visiteur@example.com'], $this->address($i));
        }
        $this->assertTooManyRequests();

        // When
        $this->post('/api/v1/users/password-reset/send', ['email' => 'quelquun-d-autre@example.com'], $this->address(8));

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
    }

    public function testRequestsRefusedForTheirAddressDoNotConsumeTheGlobalCounter(): void
    {
        // Given : une adresse insiste vingt fois (six passent, quatorze sont refusées)
        for ($i = 1; $i <= 20; $i++) {
            $this->post('/api/v1/users/password-reset/send', ['email' => 'visiteur@example.com'], $this->address($i));
        }
        $this->assertTooManyRequests();

        // When : quatre-vingt-quatorze demandes d'autres adresses (cent comptées avec les six premières)
        for ($i = 1; $i <= 94; $i++) {
            $this->post('/api/v1/users/password-reset/send', ['email' => $this->email()], $this->address($i + 20));
            $this->assertSame(204, $this->client->getResponse()->getStatusCode(), "demande $i");
        }

        // Then : le plafond commun n'a compté que ce qui est passé, la suivante est refusée par lui
        $this->post('/api/v1/users/password-reset/send', ['email' => $this->email()], $this->address(200));
        $this->assertTooManyRequests();
    }

    public function testEveryRouteOfTheListenerTakesABodyWithItsRecipient(): void
    {
        // Given : les routes que plafonne le listener
        $routes = new \ReflectionClassConstant(PublicEmailRateLimitListener::class, 'ROUTES')->getValue();
        $router = static::getContainer()->get('router')->getRouteCollection();

        // Then : chacune a un controller dont un argument porte l'adresse saisie (sinon le plafond par destinataire ne la verrait pas)
        foreach ($routes as $name) {
            $controller = $router->get($name)?->getDefault('_controller');
            $this->assertIsString($controller, "$name est introuvable");
            $parameters = new \ReflectionMethod($controller, '__invoke')->getParameters();
            $this->assertNotEmpty(
                array_filter($parameters, static fn(\ReflectionParameter $parameter): bool => is_a((string) $parameter->getType(), EmailRecipientPayload::class, true)),
                "$name doit recevoir un corps qui implémente EmailRecipientPayload.",
            );
        }
    }

    private function assertTooManyRequests(): void
    {
        $response = $this->client->getResponse();
        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame(['error' => ['message' => 'Too Many Requests']], $this->jsonBody());
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->assertLessThanOrEqual(3600, (int) $response->headers->get('Retry-After'));
    }

    /**
     * Une adresse différente à chaque demande, pour les tests qui ne mesurent pas le plafond par destinataire.
     */
    private function email(): string
    {
        return 'visiteur' . ++$this->sent . '@example.com';
    }

    private function address(int $i): string
    {
        return sprintf('203.0.%d.%d', intdiv($i, 200), $i % 200 + 1);
    }

    private function register(string $email, string $address): void
    {
        $this->post('/api/v1/users', ['email' => $email], $address);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $path, array $body, string $address): void
    {
        $this->client->request('POST', $path, content: json_encode($body, JSON_THROW_ON_ERROR), server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $address]);
    }
}
