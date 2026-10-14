<?php

namespace Repas\User\Infrastructure\Security;


use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTAuthenticatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\InvalidTokenException;
use Repas\User\Domain\Model\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Lie chaque JWT à la version des identifiants de son titulaire : changer de mot de passe révoque les jetons déjà émis
 * (ADR 0008).
 *
 * Le jeton porte `cv`, un HMAC tronqué (clé : `kernel.secret`) de l'identifiant et de la version des identifiants de
 * l'utilisateur ; à chaque requête, l'utilisateur relu en base doit avoir la même. La version n'augmente que quand le mot
 * de passe change (`User::changePassword`), **pas** quand Symfony recalcule le hash à la connexion (`User::setPassword`) : Symfony
 * émet le jeton avant ce recalcul, une empreinte du hash lui-même révoquerait aussitôt le jeton qu'on vient de donner.
 * Un jeton sans `cv` (émis avant cette règle) est refusé.
 */
final readonly class JwtCredentialsVersionListener
{
    public const string CLAIM = 'cv';

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private string $secret,
    ) {
    }

    #[AsEventListener(event: Events::JWT_CREATED)]
    public function onJwtCreated(JWTCreatedEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $event->setData($event->getData() + [self::CLAIM => $this->fingerprint($user)]);
    }

    #[AsEventListener(event: Events::JWT_AUTHENTICATED)]
    public function onJwtAuthenticated(JWTAuthenticatedEvent $event): void
    {
        $user = $event->getToken()->getUser();
        if (!$user instanceof User) {
            return;
        }

        $claim = $event->getPayload()[self::CLAIM] ?? null;
        if (!is_string($claim) || !hash_equals($this->fingerprint($user), $claim)) {
            throw new InvalidTokenException('Invalid JWT Token');
        }
    }

    private function fingerprint(User $user): string
    {
        return substr(hash_hmac('sha256', $user->getId() . '|' . $user->getCredentialsVersion(), $this->secret), 0, 16);
    }
}
