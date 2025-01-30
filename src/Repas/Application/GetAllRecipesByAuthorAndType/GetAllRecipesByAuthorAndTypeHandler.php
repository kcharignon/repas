<?php

namespace Repas\Repas\Application\GetAllRecipesByAuthorAndType;

use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Shared\Domain\Tool\Tab;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class GetAllRecipesByAuthorAndTypeHandler
{

    public function __construct(
        private RecipeRepository $recipeRepository,
        private UserRepository $userRepository,
        private RecipeTypeRepository $recipeTypeRepository,
    ) {

    }

    /**
     * @return Tab<Recipe>
     * @throws UserException
     * @throws RecipeException
     */
    public function __invoke(GetAllRecipesByAuthorAndTypeQuery $query): Tab
    {
        $author = $this->userRepository->findOneById($query->authorId);
        $type = $this->recipeTypeRepository->findOneBySlug($query->typeSlug);

        return $this->recipeRepository->findBy(
            ['authorId' => $author->getId(), 'typeSlug' => $type->getSlug()],
            ['name' => 'ASC']
        );
    }
}
