<?php

namespace Repas\Repas\Application\UpdateUnit;


readonly class UpdateUnitCommand
{

    public function __construct(
        public string $slug,
        public string $name,
        public string $symbol,
    ) {
    }
}
