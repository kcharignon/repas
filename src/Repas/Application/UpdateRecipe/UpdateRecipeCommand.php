<?php

namespace Repas\Repas\Application\UpdateRecipe;


use Repas\Shared\Domain\Tool\Tab;

class UpdateRecipeCommand
{
    /**
     * @param Tab<UpdateRecipeRowSubCommand> $rows
     */
    public function __construct(
        public string $id,
        public string $name,
        public int $serving,
        public Tab $rows,
        public string $typeSlug
    ) {
    }
}
