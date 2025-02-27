<?php

namespace Repas\Repas\Application\CopyRecipe;

use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Domain\Interface\UserRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class CopyRecipeHandler
{

    public function __construct(
        private RecipeRepository $recipeRepository,
        private UserRepository $userRepository,
    ) {
    }

    public function __invoke(CopyRecipeCommand $command): void
    {
        $originalRecipe = $this->recipeRepository->findOneById($command->recipeId);
        $author = $this->userRepository->findOneById($command->authorId);

        $recipe = Recipe::copyFromOriginal(
            UuidGenerator::new(),
            $originalRecipe,
            $author,
        );

        $this->recipeRepository->save($recipe);
    }
}
