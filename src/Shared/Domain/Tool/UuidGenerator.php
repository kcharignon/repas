<?php

namespace Repas\Shared\Domain\Tool;


use Ramsey\Uuid\Uuid;

class UuidGenerator
{
    public static function new(): string
    {
        return Uuid::uuid4()->toString();
    }
}
