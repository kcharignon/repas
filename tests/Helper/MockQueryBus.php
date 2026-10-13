<?php

namespace Repas\Tests\Helper;


use LogicException;
use Throwable;
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

    /** @var array<class-string<QueryInterface>, Throwable> */
    private array $exceptions = [];

    /**
     * @param class-string<QueryInterface> $queryClass
     */
    public function willReturn(string $queryClass, mixed $result): self
    {
        $this->results[$queryClass] = $result;

        return $this;
    }

    /**
     * La query de cette classe lève cette exception (ressource introuvable, par exemple).
     *
     * @param class-string<QueryInterface> $queryClass
     */
    public function willThrow(string $queryClass, Throwable $exception): self
    {
        $this->exceptions[$queryClass] = $exception;

        return $this;
    }

    public function ask(QueryInterface $query): mixed
    {
        $this->askedQueries[] = $query;

        if (array_key_exists($query::class, $this->exceptions)) {
            throw $this->exceptions[$query::class];
        }

        if (!array_key_exists($query::class, $this->results)) {
            throw new LogicException(sprintf('Aucun résultat programmé pour %s.', $query::class));
        }

        return $this->results[$query::class];
    }

    public function getLastAskedQuery(): ?QueryInterface
    {
        return $this->askedQueries[array_key_last($this->askedQueries)] ?? null;
    }

    /**
     * Dernière query posée de cette classe, pour les controllers qui en posent plusieurs.
     *
     * @template T of QueryInterface
     * @param class-string<T> $queryClass
     * @return T|null
     */
    public function getAskedQuery(string $queryClass): ?QueryInterface
    {
        foreach (array_reverse($this->askedQueries) as $query) {
            if ($query instanceof $queryClass) {
                return $query;
            }
        }

        return null;
    }

    public function countAskedQueries(): int
    {
        return count($this->askedQueries);
    }
}
