<?php

namespace Repas\Shared\Application\Interface;


interface CommandBusInterface
{
    public function dispatch(object $command): mixed;
}
