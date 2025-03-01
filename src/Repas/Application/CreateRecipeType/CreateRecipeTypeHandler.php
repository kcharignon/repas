<?php

namespace Repas\Repas\Application\CreateRecipeType;

use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\Repas\Domain\Model\RecipeType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class CreateRecipeTypeHandler
{
    public function __construct(
        private RecipeTypeRepository $recipeTypeRepository,
    ) {
    }

    public function __invoke(CreateRecipeTypeCommand $command): void
    {
        $recipeType = RecipeType::create($command->name, $command->image, $command->order);

        $this->recipeTypeRepository->save($recipeType);
    }
}
