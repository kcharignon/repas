<?php

namespace Repas\User\Infrastructure\Security;


use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Une connexion par mot de passe réussie (`POST /api/login_check`) retient la dernière connexion du compte. L'évènement n'existe
 * qu'après le succès : un mauvais mot de passe, une adresse non confirmée (`403 EMAIL_NOT_VERIFIED`) ou un compte désactivé n'écrivent
 * rien. Voir `UserLoginTracker`.
 */
final readonly class RecordUserLoginOnLogin
{
    public function __construct(
        private UserLoginTracker $loginTracker,
    ) {
    }

    #[AsEventListener(event: Events::AUTHENTICATION_SUCCESS)]
    public function __invoke(AuthenticationSuccessEvent $event): void
    {
        $this->loginTracker->authenticated($event->getUser());
    }
}
