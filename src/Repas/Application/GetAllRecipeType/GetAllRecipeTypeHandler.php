<?php

namespace Repas\Repas\Application\GetAllRecipeType;

use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\Repas\Domain\Model\RecipeType;
use Repas\Shared\Domain\Tool\Tab;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class GetAllRecipeTypeHandler
{


    public function __construct(
        private RecipeTypeRepository $recipeTypeRepository
    ) {
    }

    /**
     * @return Tab<RecipeType>
     */
    public function __invoke(GetAllRecipeTypeQuery $query): Tab
    {
        return $this->recipeTypeRepository->findAll();
    }
}
