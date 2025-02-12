<?php

namespace Repas\Tests\Helper;


use DateTimeImmutable;
use Repas\Shared\Domain\Clock;

readonly class FrozenClock implements Clock
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function now(string $datetime = ""): DateTimeImmutable
    {
        return $this->now;
    }
}
