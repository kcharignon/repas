<?php

namespace Repas\Repas\Application\CreateUnit;


final readonly class CreateUnitCommand
{
    public function __construct(
        public string $name,
        public string $symbol,
    ) {
    }
}
