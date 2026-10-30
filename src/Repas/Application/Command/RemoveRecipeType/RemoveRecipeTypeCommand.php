<?php

namespace Repas\Repas\Application\Command\RemoveRecipeType;


readonly class RemoveRecipeTypeCommand
{

    public function __construct(
        public string $slug,
        public string $transferToSlug,
    ) {
    }
}
