<?php

namespace Repas\Shared\Domain\Interface;


interface UuidGenerator
{
    public function generate(): string;
}
