<?php

namespace Repas\Tests\Helper;


use Repas\Shared\Application\Interface\CommandBusInterface;
use Throwable;

/**
 * Bus de commandes de test : enregistre les commandes reçues et ne les exécute pas (aucun handler, aucune base).
 */
class MockCommandBus implements CommandBusInterface
{
    /** @var list<object> */
    private array $dispatched = [];

    /** @var array<class-string, mixed> */
    private array $results = [];

    /** @var array<class-string, Throwable> */
    private array $failures = [];

    /** @var array<class-string, callable(object): mixed> */
    private array $answers = [];

    /**
     * Résultat calculé d'après la commande reçue (ex. le slug d'un ingrédient créé, tiré de son nom).
     *
     * @param class-string $commandClass
     * @param callable(object): mixed $answer
     */
    public function willAnswer(string $commandClass, callable $answer): self
    {
        $this->answers[$commandClass] = $answer;

        return $this;
    }

    /**
     * Résultat renvoyé par le handler de cette commande (ex. le slug d'un ingrédient créé).
     *
     * @param class-string $commandClass
     */
    public function willReturn(string $commandClass, mixed $result): self
    {
        $this->results[$commandClass] = $result;

        return $this;
    }

    /**
     * Le handler de cette commande échoue (une exception de domaine, par exemple) : la commande est enregistrée, puis l'exception levée.
     *
     * @param class-string $commandClass
     */
    public function willThrow(string $commandClass, Throwable $exception): self
    {
        $this->failures[$commandClass] = $exception;

        return $this;
    }

    public function dispatch(object $command): mixed
    {
        $this->dispatched[] = $command;

        if (isset($this->failures[$command::class])) {
            throw $this->failures[$command::class];
        }

        if (isset($this->answers[$command::class])) {
            return ($this->answers[$command::class])($command);
        }

        return $this->results[$command::class] ?? null;
    }

    /**
     * @return list<object>
     */
    public function dispatched(): array
    {
        return $this->dispatched;
    }
}
