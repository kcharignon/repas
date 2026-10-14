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

    public function dispatch(object $command): mixed
    {
        $this->dispatched[] = $command;

        return null;
    }

    /**
     * @return list<object>
     */
    public function dispatched(): array
    {
        return $this->dispatched;
    }
}
