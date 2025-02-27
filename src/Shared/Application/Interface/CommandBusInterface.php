<?php

namespace Repas\Shared\Application\Interface;


interface CommandBusInterface
{
    /**
     * @throw DomainException
     */
    public function dispatch(object $command): mixed;
}
