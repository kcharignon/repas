<?php

namespace Repas\User\Infrastructure\Mailer;


use DateInterval;
use Psr\Log\LoggerInterface;
use Repas\Shared\Infrastructure\Mailer\BrandedEmailContent;
use Repas\Shared\Infrastructure\Mailer\BrandedEmailLayout;
use Repas\Shared\Infrastructure\Mailer\BrandedEmailLink;
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
 * à une adresse qui a déjà un compte, qui mène à la connexion et au mot de passe oublié. Le corps est dit ici et mis en page par
 * `BrandedEmailLayout` (ADR 0031 : HTML et texte, pas de Twig depuis l'ADR 0018), au tutoiement de Repas (DRV-627), en phrases
 * courtes, sans avertissement.
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
        private BrandedEmailLayout $layout,
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

        $subject = 'Bienvenue sur Repas : choisis ton mot de passe';
        $message = $this->layout->compose(Address::create($this->from), $email, $subject, new BrandedEmailContent(
            title: 'Bienvenue sur Repas',
            preheader: 'Il reste une étape : choisis ton mot de passe, et ton compte Repas sera prêt à servir.',
            paragraphs: [
                'Ton compte est presque prêt.',
                'Pour finir, choisis ton mot de passe.',
            ],
            buttonLabel: 'Choisir mon mot de passe',
            buttonUrl: $link,
            validity: sprintf('Ce lien est valable %d heures.', $hours),
            note: "Si tu n'as pas demandé ce compte, ignore ce message : rien ne sera activé.",
            reason: 'Tu reçois ce message parce que cette adresse a servi à créer un compte Repas.',
        ));

        $this->send($message, "Échec d'envoi du lien de bienvenue d'une inscription.");
    }

    public function sendAlreadyRegistered(string $email): void
    {
        $message = $this->layout->compose(Address::create($this->from), $email, 'Tu as déjà un compte Repas', new BrandedEmailContent(
            title: 'Tu as déjà un compte',
            preheader: 'Cette adresse a déjà un compte Repas : connecte-toi, ou choisis un nouveau mot de passe.',
            paragraphs: [
                'Cette adresse a déjà un compte Repas.',
                'Tu peux te connecter directement.',
            ],
            buttonLabel: 'Me connecter',
            buttonUrl: $this->front(self::LOGIN_PATH),
            secondary: new BrandedEmailLink('Mot de passe oublié ?', 'Choisis-en un nouveau', $this->front(self::FORGOT_PASSWORD_PATH)),
            note: "Si tu n'as pas fait cette demande, ignore ce message : rien ne change.",
            reason: "Tu reçois ce message car quelqu'un a voulu créer un compte avec cette adresse.",
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
