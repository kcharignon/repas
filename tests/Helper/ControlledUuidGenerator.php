<?php

namespace Repas\Tests\Helper;


use Repas\Shared\Domain\Tool\UuidGenerator;

class ControlledUuidGenerator extends UuidGenerator
{
    public const string UUID = "unique-id";

    public static function new(): string
    {
        return static::UUID;
    }
}
