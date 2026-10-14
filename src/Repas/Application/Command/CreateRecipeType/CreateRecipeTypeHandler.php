<?php

namespace Repas\Repas\Application\Command\CreateRecipeType;

use Repas\Repas\Domain\Exception\RecipeException;
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

    public function __invoke(CreateRecipeTypeCommand $command): string
    {
        $recipeType = RecipeType::create($command->name, $command->image, $command->order);

        // Le slug dérive du nom : enregistrer sous un slug existant écraserait le type
        if ($this->recipeTypeRepository->exists($recipeType->getSlug())) {
            throw RecipeException::typeAlreadyExists($recipeType->getSlug());
        }

        $this->recipeTypeRepository->save($recipeType);

        return $recipeType->getSlug();
    }
}
