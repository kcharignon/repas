<?php

namespace Repas\Repas\Application\AddNewUnit;

use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage]
final readonly class AddNewUnitCommand
{
    public function __construct(
        public string $name,
        public string $abbreviation,
    ) {
    }

    public function getUnitContent(): array
    {
        return [
            'name' => $this->name,
            'abbreviation' => $this->abbreviation,
        ];
    }
}
