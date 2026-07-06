<?php

namespace Repas\Repas\Application\Command\CreateUnit;


final readonly class CreateUnitCommand
{
    public function __construct(
        public string $name,
        public string $symbol,
    ) {
    }
}
