<?php

namespace Repas\Shared\Infrastructure\Http;


use Repas\Shared\Domain\Exception\DomainException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Hors /api (routes Twig), une exception de domaine qui porte un code HTTP 4xx devient l'erreur HTTP correspondante : un
 * identifiant inconnu donne une page 404 au lieu d'une 500, y compris pour un administrateur (le voter le laisse passer
 * avant de chercher la ressource). Sous /api, c'est `ApiExceptionListener` qui répond en JSON.
 *
 * Priorité positive : le journal et la page d'erreur de Symfony (priorité 0 et -128) voient déjà l'erreur HTTP.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 10)]
final class WebDomainExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $pathInfo = $event->getRequest()->getPathInfo();
        if ($pathInfo === ApiExceptionListener::API_PATH_PREFIX || str_starts_with($pathInfo, ApiExceptionListener::API_PATH_PREFIX . '/')) {
            return;
        }

        $exception = $event->getThrowable();
        if (!$exception instanceof DomainException || $exception->getCode() < 400 || $exception->getCode() > 499) {
            return;
        }

        $event->setThrowable(new HttpException($exception->getCode(), $exception->getMessage(), $exception));
    }
}
