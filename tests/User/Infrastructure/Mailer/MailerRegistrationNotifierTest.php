<?php

namespace Repas\Tests\User\Infrastructure\Mailer;


use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Repas\User\Infrastructure\Mailer\MailerRegistrationNotifier;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

class MailerRegistrationNotifierTest extends TestCase
{
    public function testTheWelcomeMessageCarriesTheLinkOfThePasswordScreenInTextAndHtml(): void
    {
        // When
        $email = $this->sendWelcome();

        // Then : l'écran du front qui sert aussi au mot de passe oublié, et le secret du lien
        $this->assertSame('alexiane@example.com', $email->getTo()[0]->getAddress());
        $this->assertSame('no-reply@example.com', $email->getFrom()[0]->getAddress());
        $this->assertSame('Repas', $email->getFrom()[0]->getName());
        $this->assertSame('Bienvenue sur Repas : choisis ton mot de passe', $email->getSubject());
        $link = 'https://repas.example.com/mot-de-passe-oublie/confirmation?token=abc123&bienvenue=1';
        $this->assertStringContainsString($link, $email->getTextBody());
        $this->assertStringContainsString('href="' . htmlspecialchars($link, ENT_QUOTES) . '"', $email->getHtmlBody());
    }

    public function testTheWelcomeMessageSaysTheLinkLastsTwentyFourHoursAndCanBeIgnored(): void
    {
        // When
        $email = $this->sendWelcome();

        // Then
        foreach ([$email->getTextBody(), strip_tags($email->getHtmlBody())] as $text) {
            $this->assertStringContainsString('24 heures', $text);
            $this->assertStringContainsString('choisis ton mot de passe', $text);
            $this->assertStringContainsString("Si tu n'as pas demandé ce compte, ignore ce message", $text);
        }
    }

    public function testTheReminderLeadsToTheSignInAndToTheForgottenPasswordAndCarriesNoSecret(): void
    {
        // When
        $email = $this->sendReminder();

        // Then
        $this->assertSame('alexiane@example.com', $email->getTo()[0]->getAddress());
        $this->assertSame('Tu as déjà un compte Repas', $email->getSubject());
        foreach ([$email->getTextBody(), $email->getHtmlBody()] as $body) {
            $this->assertStringContainsString('https://repas.example.com/connexion', $body);
            $this->assertStringContainsString('https://repas.example.com/mot-de-passe-oublie', $body);
            $this->assertStringNotContainsString('token', $body);
        }
        $this->assertStringContainsString('href="https://repas.example.com/connexion"', $email->getHtmlBody());
        $this->assertStringContainsString('href="https://repas.example.com/mot-de-passe-oublie"', $email->getHtmlBody());
        $this->assertStringContainsString("Si tu n'as pas fait cette demande, ignore ce message", $email->getTextBody());
    }

    public function testBothMessagesSpeakToTheUserAsAFriend(): void
    {
        foreach ([$this->sendWelcome(), $this->sendReminder()] as $email) {
            // Then : le tutoiement de Repas (DRV-627), sans « vous », « votre », « vos » ni impératif en « -ez », ni point d'exclamation
            foreach ([$email->getSubject(), $email->getTextBody(), strip_tags($email->getHtmlBody())] as $text) {
                $this->assertDoesNotMatchRegularExpression('/\b(vous|votre|vos)\b|\b\p{L}+ez\b/iu', $text);
                $this->assertStringNotContainsString('!', $text);
            }
            $this->assertMatchesRegularExpression('/\b(ton|ta|te|tu)\b/iu', $email->getTextBody());
        }
    }

    public function testEverySentenceHasFifteenWordsAtMost(): void
    {
        foreach ([$this->sendWelcome(), $this->sendReminder()] as $email) {
            // Then : phrases courtes (principes produit § 3), l'adresse du lien mise de côté
            foreach ([$email->getSubject(), $email->getTextBody(), html_entity_decode(strip_tags(str_replace('</p>', "\n", $email->getHtmlBody())))] as $text) {
                $text = preg_replace('#https?://\S+#', '', $text);
                foreach (preg_split('/[.!?:\n]+/u', $text) as $sentence) {
                    $words = preg_split('/\s+/u', trim($sentence), -1, PREG_SPLIT_NO_EMPTY);
                    $this->assertLessThanOrEqual(15, count($words), $sentence);
                }
            }
        }
    }

    public function testTheMessagesNameNeitherAnArchitectureTermNorAWarning(): void
    {
        foreach ([$this->sendWelcome(), $this->sendReminder()] as $email) {
            $text = mb_strtolower($email->getSubject() . ' ' . $email->getTextBody());
            foreach (['jeton', 'token ', 'attention', 'danger', 'confirme ton adresse'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $text);
            }
        }
    }

    public function testATransportFailureIsLoggedAndNotRaisedAndTheAddressAndTheSecretStayOutOfTheLog(): void
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
        $notifier = new MailerRegistrationNotifier($mailer, $logger, 'Repas <no-reply@example.com>', 'https://repas.example.com');

        // When : la réponse de l'API est la même quel que soit le cas, un échec ne la change pas
        $notifier->sendWelcome('alexiane@example.com', 'abc123');
        $notifier->sendAlreadyRegistered('alexiane@example.com');

        // Then
        $this->assertCount(2, $logger->records);
        foreach ($logger->records as $record) {
            $this->assertSame('error', $record['level']);
            $this->assertStringNotContainsString('alexiane@example.com', $record['message']);
            $this->assertStringNotContainsString('abc123', json_encode([$record['message'], array_keys($record['context'])]));
        }
    }

    private function sendWelcome(): Email
    {
        return $this->sent(static fn(MailerRegistrationNotifier $notifier) => $notifier->sendWelcome('alexiane@example.com', 'abc123'));
    }

    private function sendReminder(): Email
    {
        return $this->sent(static fn(MailerRegistrationNotifier $notifier) => $notifier->sendAlreadyRegistered('alexiane@example.com'));
    }

    /**
     * @param callable(MailerRegistrationNotifier): void $send
     */
    private function sent(callable $send): Email
    {
        $mailer = new class implements MailerInterface {
            /** @var list<RawMessage> */
            public array $sent = [];

            public function send(RawMessage $message, ?\Symfony\Component\Mailer\Envelope $envelope = null): void
            {
                $this->sent[] = $message;
            }
        };
        $notifier = new MailerRegistrationNotifier($mailer, new class extends AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
            }
        }, 'Repas <no-reply@example.com>', 'https://repas.example.com/');

        $send($notifier);

        $this->assertCount(1, $mailer->sent);
        $email = $mailer->sent[0];
        $this->assertInstanceOf(Email::class, $email);

        return $email;
    }
}
