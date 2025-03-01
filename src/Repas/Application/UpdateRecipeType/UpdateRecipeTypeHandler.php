<?php

namespace Repas\Repas\Application\UpdateRecipeType;

use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class UpdateRecipeTypeHandler
{

    public function __construct(
        private RecipeTypeRepository $recipeTypeRepository,
    ) {
    }

    public function __invoke(UpdateRecipeTypeCommand $command): void
    {
        $recipeType = $this->recipeTypeRepository->findOneBySlug($command->id);

        $recipeType->update(
            name: $command->name,
            image: $command->image,
            order: $command->order
        );
        $this->recipeTypeRepository->save($recipeType);
    }
}
