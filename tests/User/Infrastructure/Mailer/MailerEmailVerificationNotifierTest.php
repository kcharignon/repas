<?php

namespace Repas\Tests\User\Infrastructure\Mailer;


use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Repas\User\Infrastructure\Mailer\MailerEmailVerificationNotifier;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

class MailerEmailVerificationNotifierTest extends TestCase
{
    public function testTheMessageCarriesTheLinkToTheFrontInTextAndHtml(): void
    {
        // Given
        $mailer = new class implements MailerInterface {
            /** @var list<RawMessage> */
            public array $sent = [];

            public function send(RawMessage $message, ?\Symfony\Component\Mailer\Envelope $envelope = null): void
            {
                $this->sent[] = $message;
            }
        };
        $notifier = new MailerEmailVerificationNotifier($mailer, new class extends AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
            }
        }, 'Repas <no-reply@example.com>', 'https://repas.example.com/');

        // When
        $notifier->send('alexiane@example.com', 'abc123');

        // Then
        $this->assertCount(1, $mailer->sent);
        $email = $mailer->sent[0];
        $this->assertInstanceOf(Email::class, $email);
        $this->assertSame('alexiane@example.com', $email->getTo()[0]->getAddress());
        $this->assertSame('no-reply@example.com', $email->getFrom()[0]->getAddress());
        $this->assertSame('Repas', $email->getFrom()[0]->getName());
        $this->assertSame('Confirmez votre adresse email', $email->getSubject());
        $link = 'https://repas.example.com/confirmation-email?token=abc123';
        $this->assertStringContainsString($link, $email->getTextBody());
        $this->assertStringContainsString('href="' . $link . '"', $email->getHtmlBody());
        $this->assertStringContainsString('24 heures', $email->getTextBody());
    }

    public function testATransportFailureIsLoggedAndNotRaisedAndTheAddressStaysOutOfTheLog(): void
    {
        // Given
        $mailer = new class implements MailerInterface {
            public function send(RawMessage $message, ?\Symfony\Component\Mailer\Envelope $envelope = null): void
            {
                throw new TransportException('Connection refused');
            }
        };
        $logger = new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
        $notifier = new MailerEmailVerificationNotifier($mailer, $logger, 'Repas <no-reply@example.com>', 'https://repas.example.com');

        // When : le compte est créé quand même, l'utilisateur redemandera le lien
        $notifier->send('alexiane@example.com', 'abc123');

        // Then
        $this->assertCount(1, $logger->records);
        $this->assertSame('error', $logger->records[0]['level']);
        $this->assertStringNotContainsString('alexiane@example.com', $logger->records[0]['message']);
        $this->assertStringNotContainsString('abc123', json_encode([$logger->records[0]['message'], array_keys($logger->records[0]['context'])]));
    }
}
