<?php

namespace Repas\User\Infrastructure\Mailer;


use DateInterval;
use Psr\Log\LoggerInterface;
use Repas\Shared\Infrastructure\Mailer\BrandedEmailContent;
use Repas\Shared\Infrastructure\Mailer\BrandedEmailLayout;
use Repas\User\Domain\Interface\PasswordResetNotifier;
use Repas\User\Domain\Model\PasswordResetToken;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Envoie le lien « mot de passe oublié » par Symfony Mailer (DSN : `MAILER_DSN`). Le lien pointe vers le front (`FRONT_URL`),
 * qui appelle ensuite l'API ; le corps est dit ici et mis en page par `BrandedEmailLayout` (ADR 0031 : HTML et texte, pas de
 * Twig depuis l'ADR 0018), au tutoiement de Repas (DRV-627) et en phrases courtes.
 */
final readonly class MailerPasswordResetNotifier implements PasswordResetNotifier
{
    /** Chemin de la page du front qui reçoit le lien (`{FRONT_URL}{chemin}?token=…`) : à garder identique à la route du front (ADR 0026). */
    public const string CONFIRMATION_PATH = '/mot-de-passe-oublie/confirmation';

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

    public function send(string $email, string $secret): void
    {
        $link = rtrim($this->frontUrl, '/') . self::CONFIRMATION_PATH . '?token=' . $secret;
        $minutes = (new DateInterval(PasswordResetToken::TTL))->i;

        $message = $this->layout->compose(Address::create($this->from), $email, 'Choisis un nouveau mot de passe', new BrandedEmailContent(
            title: 'Choisis un nouveau mot de passe',
            preheader: 'Tu as demandé à changer ton mot de passe : un appui sur le bouton, et tu en choisis un nouveau.',
            paragraphs: [
                'Tu as demandé à changer ton mot de passe.',
                'Appuie sur le bouton pour en choisir un nouveau.',
            ],
            buttonLabel: 'Réinitialiser mon mot de passe',
            buttonUrl: $link,
            validity: sprintf('Ce lien est valable %d minutes.', $minutes),
            note: "Si tu n'as pas fait cette demande, ignore ce message : rien ne change.",
            reason: "Tu reçois ce message car quelqu'un a demandé un nouveau mot de passe.",
        ));

        try {
            $this->mailer->send($message);
        } catch (TransportExceptionInterface $exception) {
            // La réponse de l'API est la même avec ou sans compte : un échec ne doit pas la changer (ADR 0026). Rien n'est relancé, l'adresse n'est pas journalisée.
            $this->logger->error("Échec d'envoi du lien de réinitialisation du mot de passe.", ['exception' => $exception]);
        }
    }
}
