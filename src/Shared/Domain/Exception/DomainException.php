<?php

namespace Repas\Shared\Domain\Exception;


use Exception;

abstract class DomainException extends Exception
{
    protected function getPrefix(): string
    {
        return basename(str_replace('\\', '/', static::class));
    }
}
