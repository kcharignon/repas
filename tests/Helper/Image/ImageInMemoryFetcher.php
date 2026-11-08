<?php

namespace Repas\Tests\Helper\Image;


use Repas\Repas\Domain\Exception\ImageException;
use Repas\Repas\Domain\Interface\ImageFetcher;
use Repas\Repas\Domain\Model\ImageUrl;

/**
 * Le téléchargement d'images des tests de handlers (ADR 0039) : des réponses programmées PAR ADRESSE (`https://exemple.org/a.png`), et la liste des appels
 * reçus (c'est ce qui prouve qu'un lien refusé ou un plafond atteint ne fait aucun appel sortant). Une adresse non programmée répond « le site ne répond
 * pas ». Aucun accès réseau, jamais.
 */
final class ImageInMemoryFetcher implements ImageFetcher
{
    /** @var array<string, string|ImageException> */
    private array $responses = [];
    /** @var list<string> */
    private array $calls = [];

    /** Les octets que l'adresse `$url` rendra. */
    public function willFetch(string $url, string $bytes): self
    {
        $this->responses[ImageUrl::parse($url)->toString()] = $bytes;

        return $this;
    }

    /** L'échec que l'adresse `$url` provoquera. */
    public function willFail(string $url, ImageException $exception): self
    {
        $this->responses[ImageUrl::parse($url)->toString()] = $exception;

        return $this;
    }

    public function fetch(ImageUrl $url): string
    {
        $this->calls[] = $url->toString();

        $response = $this->responses[$url->toString()] ?? ImageException::downloadFailed();
        if ($response instanceof ImageException) {
            throw $response;
        }

        return $response;
    }

    /** @return list<string> les adresses appelées, dans l'ordre */
    public function calls(): array
    {
        return $this->calls;
    }

    public function callCount(): int
    {
        return count($this->calls);
    }
}
