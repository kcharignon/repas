<?php

namespace Repas\Shared\Infrastructure\Http;


use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationFailureEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Met les échecs d'authentification JWT au format d'erreur de l'API : {"error": {"message": "..."}} (ADR 0007).
 *
 * Les messages sont volontairement génériques : un identifiant inconnu, un mot de passe faux et un compte
 * désactivé donnent la même réponse, pour ne pas révéler l'existence d'un compte.
 */
final class ApiAuthenticationFailureListener
{
    #[AsEventListener(event: Events::JWT_NOT_FOUND)]
    public function onJwtNotFound(AuthenticationFailureEvent $event): void
    {
        $this->unauthorized($event, 'Authentication required.');
    }

    #[AsEventListener(event: Events::JWT_INVALID)]
    public function onJwtInvalid(AuthenticationFailureEvent $event): void
    {
        $this->unauthorized($event, 'Invalid token.');
    }

    #[AsEventListener(event: Events::JWT_EXPIRED)]
    public function onJwtExpired(AuthenticationFailureEvent $event): void
    {
        $this->unauthorized($event, 'Expired token.');
    }

    #[AsEventListener(event: Events::AUTHENTICATION_FAILURE)]
    public function onAuthenticationFailure(AuthenticationFailureEvent $event): void
    {
        $this->unauthorized($event, 'Invalid credentials.');
    }

    private function unauthorized(AuthenticationFailureEvent $event, string $message): void
    {
        $event->setResponse(new JsonResponse(
            data: ['error' => ['message' => $message]],
            status: Response::HTTP_UNAUTHORIZED,
            headers: ['WWW-Authenticate' => 'Bearer'],
        ));
    }
}
