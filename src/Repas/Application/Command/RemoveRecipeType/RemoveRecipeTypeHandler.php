<?php

namespace Repas\Repas\Application\Command\RemoveRecipeType;

use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Supprime un type de recette : toutes les recettes qui le portent (de tous les utilisateurs) passent d'abord dans le type de transfert,
 * puis le type disparaît. Une seule transaction (le bus des commandes) : rien n'est déplacé si le type n'est pas supprimé.
 */
#[AsMessageHandler]
readonly class RemoveRecipeTypeHandler
{

    public function __construct(
        private RecipeTypeRepository $recipeTypeRepository,
        private RecipeRepository $recipeRepository,
    ) {
    }

    /**
     * @throws RecipeException un des deux types est inconnu, ou le type de transfert est le type supprimé
     */
    public function __invoke(RemoveRecipeTypeCommand $command): void
    {
        $recipeType = $this->recipeTypeRepository->findOneBySlug($command->slug);
        $target = $this->recipeTypeRepository->findOneBySlug($command->transferToSlug);

        $recipeType->assertCanBeReplacedBy($target);

        $this->recipeRepository->moveToType($recipeType->getSlug(), $target->getSlug());
        $this->recipeTypeRepository->delete($recipeType);
    }
}
