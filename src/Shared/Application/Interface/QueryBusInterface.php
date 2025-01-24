<?php

namespace Repas\Shared\Application\Interface;


interface QueryBusInterface
{
    public function ask(object $query): mixed;
}
