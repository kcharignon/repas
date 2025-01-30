<?php

namespace Repas\Repas\Application\GetAllRecipeByAuthor;


use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Shared\Domain\Tool\Tab;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class GetAllRecipeByAuthorHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private RecipeRepository $recipeRepository,
    ) {
    }

    /**
     * @return Tab<Recipe>
     * @throws UserException
     */
    public function __invoke(GetAllRecipeByAuthorQuery $query): Tab
    {
        // Recuperation de l'auteur s'il existe
        $author = $this->userRepository->findOneById($query->authorId);

        // Retourne les recettes de l'utilisateur
        return $this->recipeRepository->findByAuthor($author);
    }
}
