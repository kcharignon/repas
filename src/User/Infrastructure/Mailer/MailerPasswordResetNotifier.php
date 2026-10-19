<?php

namespace Repas\User\Infrastructure\Mailer;


use DateInterval;
use Psr\Log\LoggerInterface;
use Repas\User\Domain\Interface\PasswordResetNotifier;
use Repas\User\Domain\Model\PasswordResetToken;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Envoie le lien « mot de passe oublié » par Symfony Mailer (DSN : `MAILER_DSN`). Le lien pointe vers le front (`FRONT_URL`),
 * qui appelle ensuite l'API ; le corps est du texte et du HTML construits ici (pas de Twig depuis l'ADR 0018), au tutoiement
 * de Repas (DRV-627) et en phrases courtes.
 */
final readonly class MailerPasswordResetNotifier implements PasswordResetNotifier
{
    /** Chemin de la page du front qui reçoit le lien (`{FRONT_URL}{chemin}?token=…`) : à garder identique à la route du front (ADR 0026). */
    public const string CONFIRMATION_PATH = '/mot-de-passe-oublie/confirmation';

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
        $link = rtrim($this->frontUrl, '/') . self::CONFIRMATION_PATH . '?token=' . $secret;
        $minutes = (new DateInterval(PasswordResetToken::TTL))->i;

        $message = new Email()
            ->from(Address::create($this->from))
            ->to($email)
            ->subject('Choisis un nouveau mot de passe')
            ->text(sprintf(
                "Tu as demandé à changer ton mot de passe.\n\nPour en choisir un nouveau, ouvre ce lien (valable %d minutes) :\n%s\n\nSi tu n'as pas fait cette demande, ignore ce message : rien ne change.\n",
                $minutes,
                $link,
            ))
            ->html(sprintf(
                '<p>Tu as demandé à changer ton mot de passe.</p><p><a href="%1$s">Choisis un nouveau mot de passe</a> (lien valable %2$d minutes).</p><p>Si le lien ne s\'ouvre pas, copie cette adresse dans ton navigateur&nbsp;:<br>%1$s</p><p>Si tu n\'as pas fait cette demande, ignore ce message&nbsp;: rien ne change.</p>',
                htmlspecialchars($link, ENT_QUOTES),
                $minutes,
            ));

        try {
            $this->mailer->send($message);
        } catch (TransportExceptionInterface $exception) {
            // La réponse de l'API est la même avec ou sans compte : un échec ne doit pas la changer (ADR 0026). Rien n'est relancé, l'adresse n'est pas journalisée.
            $this->logger->error("Échec d'envoi du lien de réinitialisation du mot de passe.", ['exception' => $exception]);
        }
    }
}
