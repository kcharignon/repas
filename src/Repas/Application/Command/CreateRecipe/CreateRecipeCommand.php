<?php

namespace Repas\Repas\Application\Command\CreateRecipe;


use Repas\Shared\Domain\Tool\Tab;

readonly class CreateRecipeCommand
{

    /**
     * @param Tab<CreateRecipeRowSubCommand> $rows
     * @param bool $secret la recette n'est vue que de son auteur (ADR 0033) ; faux par défaut
     */
    public function __construct(
        public string $id,
        public string $name,
        public int $serving,
        public string $authorId,
        public Tab $rows,
        public string $typeSlug,
        public bool $secret = false,
    ) {
    }
}
