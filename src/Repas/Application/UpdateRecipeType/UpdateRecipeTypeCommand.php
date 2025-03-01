<?php

namespace Repas\Repas\Application\UpdateRecipeType;


readonly class UpdateRecipeTypeCommand
{

    public function __construct(
        public string $id,
        public string $name,
        public string $image,
        public int $order,
    ) {
    }
}
