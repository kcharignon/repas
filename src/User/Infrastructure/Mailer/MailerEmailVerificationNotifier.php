<?php

namespace Repas\User\Infrastructure\Mailer;


use Psr\Log\LoggerInterface;
use Repas\User\Domain\Interface\EmailVerificationNotifier;
use Repas\User\Domain\Model\EmailVerificationToken;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Envoie le lien de confirmation d'adresse par Symfony Mailer (DSN : `MAILER_DSN`). Le lien pointe vers le front
 * (`FRONT_URL`), qui appelle ensuite l'API ; le corps est du texte et du HTML construits ici (pas de Twig depuis l'ADR 0018).
 */
final readonly class MailerEmailVerificationNotifier implements EmailVerificationNotifier
{
    public function __construct(
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        #[Autowire('%env(MAILER_FROM)%')]
        private string $from,
        #[Autowire('%env(FRONT_URL)%')]
        private string $frontUrl,
    ) {
    }

    public function send(string $email, string $secret): void
    {
        $link = rtrim($this->frontUrl, '/') . '/confirmation-email?token=' . $secret;
        $hours = (int) (new \DateInterval(EmailVerificationToken::TTL))->format('%h');

        $message = new Email()
            ->from(Address::create($this->from))
            ->to($email)
            ->subject('Confirme ton adresse email')
            ->text(sprintf(
                "Bienvenue sur Repas !\n\nPour activer ton compte, confirme ton adresse email en ouvrant ce lien (valable %d heures) :\n%s\n\nSi tu n'as pas créé de compte, ignore ce message : rien ne sera activé.\n",
                $hours,
                $link,
            ))
            ->html(sprintf(
                '<p>Bienvenue sur Repas&nbsp;!</p><p>Pour activer ton compte, <a href="%1$s">confirme ton adresse email</a> (lien valable %2$d heures).</p><p>Si le lien ne s\'ouvre pas, copie cette adresse dans ton navigateur&nbsp;:<br>%1$s</p><p>Si tu n\'as pas créé de compte, ignore ce message&nbsp;: rien ne sera activé.</p>',
                htmlspecialchars($link, ENT_QUOTES),
                $hours,
            ));

        try {
            $this->mailer->send($message);
        } catch (TransportExceptionInterface $exception) {
            // Le compte est créé : l'utilisateur redemandera le lien. Rien n'est relancé ici (ADR 0020), l'adresse n'est pas journalisée.
            $this->logger->error("Échec d'envoi du lien de confirmation d'adresse email.", ['exception' => $exception]);
        }
    }
}
