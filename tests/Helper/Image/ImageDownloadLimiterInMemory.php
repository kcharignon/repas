<?php

namespace Repas\Tests\Helper\Image;


use Repas\Repas\Domain\Exception\ImageException;
use Repas\Repas\Domain\Interface\ImageDownloadLimiter;
use Repas\Shared\Domain\Exception\RateLimitedException;

/**
 * Plafond des téléchargements d'image, en mémoire (ADR 0039) : `new ImageDownloadLimiterInMemory(2)` refuse dès le troisième téléchargement d'un
 * utilisateur. Enregistre ce qu'il a consommé : c'est ce qui prouve qu'un lien refusé d'emblée ne le consomme pas.
 */
final class ImageDownloadLimiterInMemory implements ImageDownloadLimiter
{
    /** @var list<string> les utilisateurs dont un téléchargement a été consommé, dans l'ordre */
    public array $consumed = [];

    public function __construct(private readonly int $limit = 30, private readonly int $retryAfter = 900)
    {
    }

    public function consume(string $userId): void
    {
        if (count(array_filter($this->consumed, static fn (string $consumed): bool => $consumed === $userId)) >= $this->limit) {
            throw RateLimitedException::after(ImageException::RATE_LIMITED, $this->retryAfter);
        }

        $this->consumed[] = $userId;
    }
}
