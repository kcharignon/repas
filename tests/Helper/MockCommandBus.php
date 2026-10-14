<?php

namespace Repas\Tests\Helper;


use Repas\Shared\Application\Interface\CommandBusInterface;

/**
 * Bus de commandes de test : enregistre les commandes reçues et ne les exécute pas (aucun handler, aucune base).
 */
class MockCommandBus implements CommandBusInterface
{
    /** @var list<object> */
    private array $dispatched = [];

    /** @var array<class-string, mixed> */
    private array $results = [];

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

    public function dispatch(object $command): mixed
    {
        $this->dispatched[] = $command;

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
