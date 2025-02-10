<?php

namespace Repas\Repas\Application\CreateRecipe;


use Repas\Shared\Domain\Tool\Tab;

readonly class CreateRecipeCommand
{

    /**
     * @param Tab<CreateRecipeRowSubCommand> $rows
     */
    public function __construct(
        public string $id,
        public string $name,
        public int $serving,
        public string $authorId,
        public Tab $rows,
        public string $typeSlug
    ) {
    }
}

readonly class CreateRecipeRowSubCommand
{
    public function __construct(
        public string $ingredientSlug,
        public string $unitSlug,
        public float $quantity,
    ) {
    }
}
