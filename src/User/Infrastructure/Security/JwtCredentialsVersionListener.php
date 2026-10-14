<?php

namespace Repas\User\Infrastructure\Security;


use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTAuthenticatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\InvalidTokenException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

/**
 * Lie chaque JWT au mot de passe de son titulaire : changer de mot de passe révoque les jetons déjà émis (ADR 0008).
 *
 * Le jeton porte l'empreinte du hash du mot de passe (`pwd`) ; à chaque requête, l'utilisateur relu en base doit avoir la
 * même. L'empreinte est un HMAC tronqué (clé : `kernel.secret`), pas le hash lui-même : le contenu d'un JWT est lisible
 * de tous. Un jeton sans empreinte (émis avant cette règle) est refusé.
 */
final readonly class JwtPasswordFingerprintListener
{
    public const string CLAIM = 'pwd';

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private string $secret,
    ) {
    }

    #[AsEventListener(event: Events::JWT_CREATED)]
    public function onJwtCreated(JWTCreatedEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof PasswordAuthenticatedUserInterface) {
            return;
        }

        $event->setData($event->getData() + [self::CLAIM => $this->fingerprint($user)]);
    }

    #[AsEventListener(event: Events::JWT_AUTHENTICATED)]
    public function onJwtAuthenticated(JWTAuthenticatedEvent $event): void
    {
        $user = $event->getToken()->getUser();
        if (!$user instanceof PasswordAuthenticatedUserInterface) {
            return;
        }

        $claim = $event->getPayload()[self::CLAIM] ?? null;
        if (!is_string($claim) || !hash_equals($this->fingerprint($user), $claim)) {
            throw new InvalidTokenException('Invalid JWT Token');
        }
    }

    private function fingerprint(PasswordAuthenticatedUserInterface $user): string
    {
        return substr(hash_hmac('sha256', (string) $user->getPassword(), $this->secret), 0, 16);
    }
}
