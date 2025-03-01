<?php

namespace Repas\Repas\Application\CreateRecipeType;


readonly class CreateRecipeTypeCommand
{

    public function __construct(
        public string $name,
        public string $image,
        public int $order,
    ) {
    }
}
