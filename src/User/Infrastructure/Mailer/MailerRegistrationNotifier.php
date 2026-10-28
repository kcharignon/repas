<?php

namespace Repas\User\Infrastructure\Mailer;


use DateInterval;
use Psr\Log\LoggerInterface;
use Repas\User\Domain\Interface\RegistrationNotifier;
use Repas\User\Domain\Model\PasswordResetToken;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Envoie par Symfony Mailer (DSN : `MAILER_DSN`) les deux emails de l'inscription (ADR 0020 amendé) : la bienvenue, dont le lien ouvre
 * l'écran de choix du mot de passe du front (celui du mot de passe oublié, `MailerPasswordResetNotifier::CONFIRMATION_PATH`), et le rappel
 * à une adresse qui a déjà un compte, qui mène à la connexion et au mot de passe oublié. Texte et HTML construits ici (pas de Twig depuis
 * l'ADR 0018), au tutoiement de Repas (DRV-627), en phrases courtes, sans avertissement.
 */
final readonly class MailerRegistrationNotifier implements RegistrationNotifier
{
    /** Pages du front où mène le rappel « tu as déjà un compte » : à garder identiques aux routes du front. */
    public const string LOGIN_PATH = '/connexion';
    public const string FORGOT_PASSWORD_PATH = '/mot-de-passe-oublie';

    /** Ajouté au lien de bienvenue pour que le front dise « Bienvenue » plutôt que « nouveau mot de passe » ; il ne change rien au lien. */
    public const string WELCOME_HINT = 'bienvenue=1';

    public function __construct(
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        #[Autowire('%env(MAILER_FROM)%')]
        private string $from,
        #[Autowire('%env(FRONT_URL)%')]
        private string $frontUrl,
    ) {
    }

    public function sendWelcome(string $email, string $secret): void
    {
        $link = $this->front(MailerPasswordResetNotifier::CONFIRMATION_PATH) . '?token=' . $secret . '&' . self::WELCOME_HINT;
        $hours = (new DateInterval(PasswordResetToken::WELCOME_TTL))->h;

        $message = new Email()
            ->from(Address::create($this->from))
            ->to($email)
            ->subject('Bienvenue sur Repas : choisis ton mot de passe')
            ->text(sprintf(
                "Bienvenue sur Repas.\n\nPour finir, choisis ton mot de passe avec ce lien (valable %d heures) :\n%s\n\nSi tu n'as pas demandé ce compte, ignore ce message : rien ne sera activé.\n",
                $hours,
                $link,
            ))
            ->html(sprintf(
                '<p>Bienvenue sur Repas.</p><p>Pour finir, <a href="%1$s">choisis ton mot de passe</a> (lien valable %2$d heures).</p><p>Si le lien ne s\'ouvre pas, copie cette adresse dans ton navigateur&nbsp;:<br>%1$s</p><p>Si tu n\'as pas demandé ce compte, ignore ce message&nbsp;: rien ne sera activé.</p>',
                htmlspecialchars($link, ENT_QUOTES),
                $hours,
            ));

        $this->send($message, "Échec d'envoi du lien de bienvenue d'une inscription.");
    }

    public function sendAlreadyRegistered(string $email): void
    {
        $login = $this->front(self::LOGIN_PATH);
        $forgotten = $this->front(self::FORGOT_PASSWORD_PATH);

        $message = new Email()
            ->from(Address::create($this->from))
            ->to($email)
            ->subject('Tu as déjà un compte Repas')
            ->text(sprintf(
                "Tu as déjà un compte Repas avec cette adresse.\n\nPour te connecter, ouvre ce lien :\n%s\n\nMot de passe oublié ? Choisis-en un nouveau ici :\n%s\n\nSi tu n'as pas fait cette demande, ignore ce message : rien ne change.\n",
                $login,
                $forgotten,
            ))
            ->html(sprintf(
                '<p>Tu as déjà un compte Repas avec cette adresse.</p><p><a href="%1$s">Connecte-toi</a>.</p><p>Mot de passe oublié&nbsp;? <a href="%2$s">Choisis-en un nouveau</a>.</p><p>Si tu n\'as pas fait cette demande, ignore ce message&nbsp;: rien ne change.</p>',
                htmlspecialchars($login, ENT_QUOTES),
                htmlspecialchars($forgotten, ENT_QUOTES),
            ));

        $this->send($message, "Échec d'envoi du rappel d'un compte déjà inscrit.");
    }

    private function front(string $path): string
    {
        return rtrim($this->frontUrl, '/') . $path;
    }

    private function send(Email $message, string $failure): void
    {
        try {
            $this->mailer->send($message);
        } catch (TransportExceptionInterface $exception) {
            // La réponse de l'API est la même dans tous les cas : un échec ne doit pas la changer (ADR 0020 amendé). Rien n'est relancé, l'adresse n'est pas journalisée.
            $this->logger->error($failure, ['exception' => $exception]);
        }
    }
}
