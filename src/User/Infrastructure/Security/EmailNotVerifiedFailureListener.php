<?php

namespace Repas\User\Infrastructure\Security;


use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationFailureEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Repas\User\Domain\Exception\EmailNotVerifiedException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Connexion d'un compte dont l'adresse email n'est pas confirmée : `403 {"error":{"message":"EMAIL_NOT_VERIFIED"}}`
 * (ADR 0020). Le front reconnaît ce message pour proposer le renvoi du lien. Le mot de passe a déjà été vérifié
 * (`UserChecker::checkPostAuth`) : l'état du compte n'est révélé qu'à celui qui le connaît.
 *
 * Passe avant `ApiAuthenticationFailureListener` (priorité plus haute), qui répondrait sinon `401 Invalid credentials.`.
 */
final class EmailNotVerifiedFailureListener
{
    #[AsEventListener(event: Events::AUTHENTICATION_FAILURE, priority: 10)]
    public function __invoke(AuthenticationFailureEvent $event): void
    {
        if (!$event->getException() instanceof EmailNotVerifiedException) {
            return;
        }

        $event->setResponse(new JsonResponse(
            data: ['error' => ['message' => EmailNotVerifiedException::MESSAGE]],
            status: Response::HTTP_FORBIDDEN,
        ));
        $event->stopPropagation();
    }
}
