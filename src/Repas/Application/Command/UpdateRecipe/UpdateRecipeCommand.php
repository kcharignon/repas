<?php

namespace Repas\Repas\Application\Command\UpdateRecipe;


use Repas\Shared\Domain\Tool\Tab;

class UpdateRecipeCommand
{
    /**
     * @param Tab<UpdateRecipeRowSubCommand> $rows
     * @param bool|null $secret le nouveau choix « recette secrète » (ADR 0033) ; `null` : inchangé, jamais remis à zéro par un ancien client
     * @param string|null $userId l'utilisateur qui modifie (le jeton) : seul l'auteur change le drapeau `secret`, un administrateur qui modifie la
     *                            recette d'un autre ne le peut pas ; sans utilisateur, personne ne le change
     */
    public function __construct(
        public string $id,
        public string $name,
        public int $serving,
        public Tab $rows,
        public string $typeSlug,
        public ?int $expectedVersion = null,
        public ?bool $secret = null,
        public ?string $userId = null,
    ) {
    }
}
