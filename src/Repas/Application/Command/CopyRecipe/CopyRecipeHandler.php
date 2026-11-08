<?php

namespace Repas\Repas\Application\Command\CopyRecipe;

use Repas\Repas\Application\Service\RecipeCopier;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Copie une recette chez un utilisateur (ADR 0028 décision 8, `docs/ingredients/rapprochement.md`). Le rapprochement des ingrédients et la copie
 * vivent dans `RecipeCopier`, que partage l'import d'une recette Marmiton (ADR 0037) : ce cas d'usage juge le plafond du compte, charge la recette
 * et la lui donne.
 */
#[AsMessageHandler]
readonly class CopyRecipeHandler
{
    public function __construct(
        private RecipeRepository $recipeRepository,
        private RecipeCopier $copier,
    ) {
    }

    /**
     * @throws RecipeException la recette est inconnue ou secrète et d'un autre (pour lui elle n'existe pas : ADR 0033, administrateur compris),
     *                         ou le copieur a déjà `Recipe::MAX_PER_AUTHOR_WITH_COPIES` recettes
     */
    public function __invoke(CopyRecipeCommand $command): void
    {
        // Le plafond du compte d'abord (copies comprises, plus large que celui de l'écriture) : un compte qui l'a atteint ne coûte rien de plus
        $this->copier->assertCanCopy($command->authorId);

        $this->copier->copy($this->recipeRepository->findOneById($command->recipeId), $command->authorId, $command->newRecipeId);
    }
}
