<?php

namespace Repas\Tests\Helper;


use Repas\Repas\Domain\Exception\MarmitonImportException;
use Repas\Repas\Domain\Interface\MarmitonPageReader;
use Repas\Repas\Domain\Model\MarmitonRecipePage;
use Repas\Repas\Domain\Model\MarmitonUrl;

/**
 * Le lecteur de page Marmiton des tests de handlers (ADR 0037, MAR-001) : des réponses programmées PAR IDENTIFIANT Marmiton, et la liste
 * des appels reçus (c'est ce qui prouve qu'une recette déjà connue ne fait aucun appel sortant). Un identifiant non programmé répond
 * « recette introuvable », comme Marmiton pour un identifiant inconnu. Aucun accès réseau, jamais.
 */
final class MarmitonInMemoryPageReader implements MarmitonPageReader
{
    /** @var array<string, MarmitonRecipePage|MarmitonImportException> */
    private array $responses = [];
    /** @var list<MarmitonUrl> */
    private array $calls = [];

    /** La page que l'identifiant `$marmitonId` rendra. */
    public function willRead(string $marmitonId, MarmitonRecipePage $page): self
    {
        $this->responses[$marmitonId] = $page;

        return $this;
    }

    /** L'échec que l'identifiant `$marmitonId` provoquera. */
    public function willFail(string $marmitonId, MarmitonImportException $exception): self
    {
        $this->responses[$marmitonId] = $exception;

        return $this;
    }

    public function read(MarmitonUrl $url): MarmitonRecipePage
    {
        $this->calls[] = $url;

        $response = $this->responses[$url->id] ?? MarmitonImportException::recipeNotFound();
        if ($response instanceof MarmitonImportException) {
            throw $response;
        }

        return $response;
    }

    /** @return list<MarmitonUrl> les adresses lues, dans l'ordre */
    public function calls(): array
    {
        return $this->calls;
    }

    public function callCount(): int
    {
        return count($this->calls);
    }
}
