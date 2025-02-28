<?php

namespace Repas\Shared\Domain\Tool;


use Ramsey\Uuid\Uuid;
use Repas\Shared\Domain\Interface\UuidGenerator as UuidGeneratorInterface;

class UuidGenerator implements UuidGeneratorInterface
{
    public static function new(): string
    {
        return Uuid::uuid4()->toString();
    }

    public function generate(): string
    {
        return Uuid::uuid4()->toString();
    }
}
