<?php

namespace Repas\Repas\Application\Command\CreateUnit;


use Repas\Repas\Domain\Model\Dimension;

final readonly class CreateUnitCommand
{
    public function __construct(
        public string $name,
        public string $symbol,
        public ?Dimension $dimension = null,
        public ?float $baseFactor = null,
    ) {
    }
}
