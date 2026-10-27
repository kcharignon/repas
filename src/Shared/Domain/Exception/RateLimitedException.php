<?php

namespace Repas\Shared\Domain\Exception;


/**
 * Un plafond de débit atteint dans un cas d'usage : `429`, et le délai d'attente en secondes, que la réponse HTTP reprend dans
 * `Retry-After` (`ApiExceptionListener`). Le message est le code métier du plafond (`PASSWORD_RATE_LIMITED`).
 */
final class RateLimitedException extends DomainException
{
    private function __construct(string $message, private readonly int $retryAfter)
    {
        parent::__construct($message, 429);
    }

    public static function after(string $message, int $retryAfter): self
    {
        return new self($message, max(1, $retryAfter));
    }

    /**
     * Le délai d'attente, en secondes (1 au moins).
     */
    public function retryAfter(): int
    {
        return $this->retryAfter;
    }
}
