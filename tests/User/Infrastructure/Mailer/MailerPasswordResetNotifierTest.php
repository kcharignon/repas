<?php

namespace Repas\Tests\User\Infrastructure\Mailer;


use PHPUnit\Framework\TestCase;
use Repas\Shared\Infrastructure\Mailer\BrandedEmailLayout;
use Repas\Tests\Helper\VisibleEmailText;
use Psr\Log\AbstractLogger;
use Repas\User\Infrastructure\Mailer\MailerPasswordResetNotifier;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

class MailerPasswordResetNotifierTest extends TestCase
{
    public function testTheMessageCarriesTheLinkToTheFrontInTextAndHtml(): void
    {
        // When
        $email = $this->sendThePasswordResetEmail();

        // Then
        $this->assertSame('alexiane@example.com', $email->getTo()[0]->getAddress());
        $this->assertSame('no-reply@example.com', $email->getFrom()[0]->getAddress());
        $this->assertSame('Repas', $email->getFrom()[0]->getName());
        $this->assertSame('Choisis un nouveau mot de passe', $email->getSubject());
        $link = 'https://repas.example.com/mot-de-passe-oublie/confirmation?token=abc123';
        $this->assertStringContainsString($link, $email->getTextBody());
        $this->assertStringContainsString('href="' . $link . '"', $email->getHtmlBody());
    }

    public function testTheMessageSaysTheLinkLastsThirtyMinutesAndCanBeIgnored(): void
    {
        // When
        $email = $this->sendThePasswordResetEmail();

        // Then
        foreach ([$email->getTextBody(), VisibleEmailText::of($email->getHtmlBody())] as $text) {
            $this->assertStringContainsString('30 minutes', $text);
            $this->assertStringContainsString("Si tu n'as pas fait cette demande, ignore ce message", $text);
            $this->assertStringContainsString('rien ne change', $text);
        }
    }

    public function testTheMessageSpeaksToTheUserAsAFriendInTheSubjectTheTextAndTheHtml(): void
    {
        // When
        $email = $this->sendThePasswordResetEmail();

        // Then : le tutoiement de Repas (DRV-627), sans « vous », « votre », « vos » ni impératif en « -ez »
        foreach ([$email->getSubject(), $email->getTextBody(), VisibleEmailText::of($email->getHtmlBody())] as $text) {
            $this->assertDoesNotMatchRegularExpression('/\b(vous|votre|vos)\b|\b\p{L}+ez\b/iu', $text);
        }
        $this->assertStringContainsString('ton mot de passe', $email->getTextBody());
        $this->assertStringContainsString('ton mot de passe', $email->getHtmlBody());
    }

    public function testEverySentenceHasFifteenWordsAtMost(): void
    {
        // When
        $email = $this->sendThePasswordResetEmail();

        // Then : phrases courtes (principes produit § 3), l'adresse du lien mise de côté
        foreach ([$email->getSubject(), $email->getTextBody(), VisibleEmailText::of($email->getHtmlBody())] as $text) {
            $this->assertStringNotContainsString('!', $text);
            $text = preg_replace('#https?://\S+#', '', $text);
            foreach (preg_split('/[.!?:\n]+/u', $text) as $sentence) {
                $words = preg_split('/\s+/u', trim($sentence), -1, PREG_SPLIT_NO_EMPTY);
                $this->assertLessThanOrEqual(15, count($words), $sentence);
            }
        }
    }

    public function testTheMessageWearsTheBrandLayoutWithAPreheaderAndTheLinkInClear(): void
    {
        foreach ([$this->sendThePasswordResetEmail()] as $email) {
            // Then : mise en page de marque (ADR 0031), accroche cachée de 80 à 100 caractères, objet non vide, texte brut présent
            $html = $email->getHtmlBody();
            $this->assertNotSame('', trim($email->getSubject()));
            $this->assertStringNotContainsString('!', $email->getSubject());
            $this->assertNotSame('', trim($email->getTextBody()));
            $this->assertStringContainsString('<html lang="fr"', $html);
            $this->assertStringContainsString('@media (prefers-color-scheme: dark)', $html);
            $this->assertMatchesRegularExpression('/\balt="[^"]*"/', $html);
            $this->assertBetween(80, 100, mb_strlen(VisibleEmailText::preheaderOf($html)));
            $this->assertStringContainsString('Réinitialiser mon mot de passe', $email->getTextBody());
            $this->assertStringContainsString('Réinitialiser mon mot de passe', VisibleEmailText::of($email->getHtmlBody()));
            // L'adresse du bouton est aussi écrite en clair, dans le HTML (lien à copier) comme dans le texte
            preg_match_all('#href="(https://[^"]+)"#', $html, $links);
            $this->assertNotEmpty($links[1]);
            $firstLink = html_entity_decode($links[1][0]);
            $this->assertGreaterThanOrEqual(2, substr_count($html, $links[1][0]));
            $this->assertStringContainsString("\n" . $firstLink . "\n", $email->getTextBody());
            // Aucune ressource externe autre que l'icône du site
            preg_match_all('#\bsrc="([^"]*)"#', $html, $sources);
            $this->assertSame(['https://repas.example.com/icons/icon-192.png'], $sources[1]);
        }
    }

    private function assertBetween(int $min, int $max, int $value): void
    {
        $this->assertGreaterThanOrEqual($min, $value);
        $this->assertLessThanOrEqual($max, $value);
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
        $notifier = new MailerPasswordResetNotifier($mailer, new BrandedEmailLayout('https://repas.example.com'), $logger, 'Repas <no-reply@example.com>', 'https://repas.example.com');

        // When : la réponse de l'API est la même avec ou sans compte, un échec ne la change pas
        $notifier->send('alexiane@example.com', 'abc123');

        // Then
        $this->assertCount(1, $logger->records);
        $this->assertSame('error', $logger->records[0]['level']);
        $this->assertStringNotContainsString('alexiane@example.com', $logger->records[0]['message']);
        $this->assertStringNotContainsString('abc123', json_encode([$logger->records[0]['message'], array_keys($logger->records[0]['context'])]));
    }

    private function sendThePasswordResetEmail(): Email
    {
        $mailer = new class implements MailerInterface {
            /** @var list<RawMessage> */
            public array $sent = [];

            public function send(RawMessage $message, ?\Symfony\Component\Mailer\Envelope $envelope = null): void
            {
                $this->sent[] = $message;
            }
        };
        $notifier = new MailerPasswordResetNotifier($mailer, new BrandedEmailLayout('https://repas.example.com'), new class extends AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
            }
        }, 'Repas <no-reply@example.com>', 'https://repas.example.com/');

        $notifier->send('alexiane@example.com', 'abc123');

        $this->assertCount(1, $mailer->sent);
        $email = $mailer->sent[0];
        $this->assertInstanceOf(Email::class, $email);

        return $email;
    }
}
