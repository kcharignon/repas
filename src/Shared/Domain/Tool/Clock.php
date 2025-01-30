<?php

namespace Repas\Shared\Domain\Tool;


use DateMalformedStringException;
use DateTimeImmutable;

class Clock implements \Repas\Shared\Domain\Clock
{
    /**
     * @throws DateMalformedStringException
     */
    public function now(string $datetime = 'now'): DateTimeImmutable
    {
        return new DateTimeImmutable($datetime);
    }
}
