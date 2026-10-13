<?php

namespace Repas\Tests\Helper;


use LogicException;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Application\Interface\QueryInterface;

/**
 * Double de QueryBus pour les tests de controllers : enregistre les queries posées et renvoie la valeur
 * programmée pour leur classe. Ne passe par aucun handler, aucun loader, aucune base.
 */
class MockQueryBus implements QueryBusInterface
{
    /** @var list<QueryInterface> */
    private array $askedQueries = [];

    /** @var array<class-string<QueryInterface>, mixed> */
    private array $results = [];

    /**
     * @param class-string<QueryInterface> $queryClass
     */
    public function willReturn(string $queryClass, mixed $result): self
    {
        $this->results[$queryClass] = $result;

        return $this;
    }

    public function ask(QueryInterface $query): mixed
    {
        $this->askedQueries[] = $query;

        if (!array_key_exists($query::class, $this->results)) {
            throw new LogicException(sprintf('Aucun résultat programmé pour %s.', $query::class));
        }

        return $this->results[$query::class];
    }

    public function getLastAskedQuery(): ?QueryInterface
    {
        return $this->askedQueries[array_key_last($this->askedQueries)] ?? null;
    }

    public function countAskedQueries(): int
    {
        return count($this->askedQueries);
    }
}
