<?php

namespace Repas\Tests\User\Infrastructure\Mailer;


use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

/**
 * Le DSN de production (`brevo+api://CLE@default`, ADR 0020 §11) est compris par l'application et envoie par HTTPS : DigitalOcean
 * bloque le SMTP sortant (25, 465, 587), l'API de Brevo passe par le port 443.
 */
class BrevoTransportTest extends TestCase
{
    public function testTheBrevoApiDsnSendsOverHttpsWithTheApiKey(): void
    {
        // Given
        $client = new MockHttpClient(new MockResponse('{"messageId":"<abc@brevo>"}', ['http_code' => 201]));
        $transport = Transport::fromDsn('brevo+api://la-cle-d-api@default', null, $client);
        $email = new Email()->from('Repas <no-reply@kantin.eu>')->to('alexiane@example.com')->subject('Confirmez votre adresse email')->text('Le lien.');

        // When
        $transport->send($email);

        // Then
        $this->assertSame('brevo+api://api.brevo.com', (string) $transport);
        $this->assertSame(1, $client->getRequestsCount());
    }
}
