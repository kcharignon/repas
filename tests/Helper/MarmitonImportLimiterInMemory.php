<?php

namespace Repas\Tests\Helper;


use Repas\Repas\Domain\Exception\MarmitonImportException;
use Repas\Repas\Domain\Interface\MarmitonImportLimiter;
use Repas\Shared\Domain\Exception\RateLimitedException;

/**
 * Plafond des lectures de page Marmiton, en mémoire (ADR 0037 décision 14) : `new MarmitonImportLimiterInMemory(2)` refuse dès la troisième lecture
 * d'un utilisateur. Enregistre ce qu'il a consommé : c'est ce qui prouve qu'une copie d'une recette neutre déjà connue ne le consomme pas.
 */
class MarmitonImportLimiterInMemory implements MarmitonImportLimiter
{
    /** @var list<string> les utilisateurs dont une lecture a été consommée, dans l'ordre */
    public array $consumed = [];

    public function __construct(private readonly int $limit = 20, private readonly int $retryAfter = 900)
    {
    }

    public function consume(string $userId): void
    {
        if (count(array_filter($this->consumed, static fn(string $consumed): bool => $consumed === $userId)) >= $this->limit) {
            throw RateLimitedException::after(MarmitonImportException::RATE_LIMITED, $this->retryAfter);
        }

        $this->consumed[] = $userId;
    }
}
