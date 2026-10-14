<?php

namespace Repas\Shared\Infrastructure\Http;


use Repas\Shared\Domain\Exception\DomainException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Throwable;

/**
 * Transforme toute exception levée sous /api en {"error": {"message": "..."}} (ADR 0007).
 *
 * Priorité négative : le listener du firewall (priorité 1) passe avant. Il renvoie lui-même la réponse 401
 * quand l'utilisateur n'est pas authentifié, et convertit un refus d'accès en AccessDeniedHttpException (403)
 * que ce listener met ensuite en forme.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: -10)]
final class ApiExceptionListener
{
    public const API_PATH_PREFIX = '/api';
    private const GENERIC_MESSAGE = 'An unexpected error occurred.';

    public function __invoke(ExceptionEvent $event): void
    {
        if (!$this->isApiRequest($event->getRequest()->getPathInfo())) {
            return;
        }

        $exception = $event->getThrowable();

        $event->setResponse(new JsonResponse(
            data: ['error' => ['message' => $this->getMessage($exception)]],
            status: $this->getStatusCode($exception),
            headers: $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [],
        ));
    }

    private function isApiRequest(string $pathInfo): bool
    {
        return $pathInfo === self::API_PATH_PREFIX || str_starts_with($pathInfo, self::API_PATH_PREFIX . '/');
    }

    private function getStatusCode(Throwable $exception): int
    {
        return match (true) {
            $exception instanceof DomainException => $this->getDomainStatusCode($exception),
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            default => Response::HTTP_INTERNAL_SERVER_ERROR,
        };
    }

    /**
     * Les exceptions de domaine portent leur code HTTP (404 pour notFound(), 403 pour un refus métier).
     * Un code qui n'est pas une erreur HTTP donne 422.
     */
    private function getDomainStatusCode(DomainException $exception): int
    {
        $code = $exception->getCode();

        return $code >= 400 && $code <= 599 ? $code : Response::HTTP_UNPROCESSABLE_ENTITY;
    }

    private function getMessage(Throwable $exception): string
    {
        if ($exception instanceof DomainException) {
            return $exception->getMessage();
        }

        if ($exception instanceof HttpExceptionInterface) {
            $previous = $exception->getPrevious();
            if ($previous instanceof ValidationFailedException) {
                return $this->formatViolations($previous);
            }

            return Response::$statusTexts[$exception->getStatusCode()] ?? self::GENERIC_MESSAGE;
        }

        return self::GENERIC_MESSAGE;
    }

    private function formatViolations(ValidationFailedException $exception): string
    {
        $messages = [];
        foreach ($exception->getViolations() as $violation) {
            $path = $violation->getPropertyPath();
            $messages[] = $path === '' ? (string) $violation->getMessage() : sprintf('%s: %s', $path, $violation->getMessage());
        }

        return implode(' ', $messages);
    }
}
