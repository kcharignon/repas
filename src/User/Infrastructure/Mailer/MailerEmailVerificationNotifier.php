<?php

namespace Repas\User\Infrastructure\Mailer;


use Psr\Log\LoggerInterface;
use Repas\Shared\Infrastructure\Mailer\BrandedEmailContent;
use Repas\Shared\Infrastructure\Mailer\BrandedEmailLayout;
use Repas\User\Domain\Interface\EmailVerificationNotifier;
use Repas\User\Domain\Model\EmailVerificationToken;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Envoie le lien de confirmation d'adresse par Symfony Mailer (DSN : `MAILER_DSN`). Le lien pointe vers le front
 * (`FRONT_URL`), qui appelle ensuite l'API ; le corps est dit ici et mis en page par `BrandedEmailLayout` (ADR 0031 :
 * HTML et texte, pas de Twig depuis l'ADR 0018), au tutoiement de Repas (DRV-627) et en phrases courtes.
 */
final readonly class MailerEmailVerificationNotifier implements EmailVerificationNotifier
{
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
        $link = rtrim($this->frontUrl, '/') . '/confirmation-email?token=' . $secret;
        $hours = (int) (new \DateInterval(EmailVerificationToken::TTL))->format('%h');

        $message = $this->layout->compose(Address::create($this->from), $email, 'Confirme ton adresse email', new BrandedEmailContent(
            title: 'Confirme ton adresse email',
            preheader: 'Un dernier geste : confirme ton adresse email, et ton compte Repas sera activé aussitôt.',
            paragraphs: [
                'Bienvenue sur Repas.',
                'Pour activer ton compte, confirme ton adresse email.',
            ],
            buttonLabel: 'Confirmer mon adresse',
            buttonUrl: $link,
            validity: sprintf('Ce lien est valable %d heures.', $hours),
            note: "Si tu n'as pas créé de compte, ignore ce message : rien ne sera activé.",
            reason: 'Tu reçois ce message parce que cette adresse a servi à créer un compte Repas.',
        ));

        try {
            $this->mailer->send($message);
        } catch (TransportExceptionInterface $exception) {
            // Le compte est créé : l'utilisateur redemandera le lien. Rien n'est relancé ici (ADR 0020), l'adresse n'est pas journalisée.
            $this->logger->error("Échec d'envoi du lien de confirmation d'adresse email.", ['exception' => $exception]);
        }
    }
}
