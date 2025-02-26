<?php

namespace Repas\Tests\Helper;


use DateTimeImmutable;
use Psr\Clock\ClockInterface;

readonly class FrozenClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
