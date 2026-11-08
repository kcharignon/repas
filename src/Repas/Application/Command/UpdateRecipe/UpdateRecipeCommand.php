<?php

namespace Repas\Repas\Application\Command\UpdateRecipe;


use Repas\Repas\Application\Command\CreateRecipe\RecipeComponentSubCommand;
use Repas\Shared\Domain\Tool\Tab;

class UpdateRecipeCommand
{
    /**
     * @param Tab<UpdateRecipeRowSubCommand> $rows
     * @param bool|null $secret le nouveau choix « recette secrète » (ADR 0033) ; `null` : inchangé, jamais remis à zéro par un ancien client
     * @param string|null $userId l'utilisateur qui modifie (le jeton) : seul l'auteur change le drapeau `secret`, un administrateur qui modifie la
     *                            recette d'un autre ne le peut pas ; sans utilisateur, personne ne le change
     * @param bool $byAdministrator l'utilisateur est administrateur (jugé par le contrôleur) : lui seul modifie une recette SANS PROPRIÉTAIRE, la
     *                              neutre d'une recette Marmiton (ADR 0037, amendement du 2026-11-08) ; ses slugs d'ingrédients sont alors lus pour LUI
     * @param Tab<RecipeComponentSubCommand>|null $components les recettes que la recette utilise (ADR 0040 décision 24), dans l'ordre de leur
     *                                                        position : `null` = inchangé (un ancien client qui renvoie `rows` seul n'efface jamais un
     *                                                        composant), vide = tout retirer, sinon remplacement complet
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
        public bool $byAdministrator = false,
        public ?Tab $components = null,
    ) {
    }
}
