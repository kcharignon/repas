<?php

namespace Repas\Shared\Domain;


use DateTimeImmutable;

interface Clock
{
    public function now(string $datetime): DateTimeImmutable;
}
