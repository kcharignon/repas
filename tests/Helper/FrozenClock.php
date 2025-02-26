<?php

namespace Repas\Tests\Helper;


use DateTimeImmutable;
use Psr\Clock\ClockInterface;

readonly class FrozenClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(?DateTimeImmutable $now = null)
    {
        $this->now = $now ?? new DateTimeImmutable();
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
