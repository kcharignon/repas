<?php

namespace Repas\Repas\Infrastructure\Loader;


use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Repas\Domain\Model\RecipeType;
use Repas\Repas\Infrastructure\Entity\Recipe as RecipeEntity;
use Repas\Repas\Infrastructure\Repository\PostgreSQLRepository;
use Repas\Shared\Domain\Tool\Tab;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;

readonly class RecipeLoader extends PostgreSQLRepository
{

    public function __construct(
        ManagerRegistry $managerRegistry,
        private RecipeTypeRepository $recipeTypeRepository,
        private UserRepository $userRepository,
    ) {
        parent::__construct($managerRegistry, RecipeEntity::class);
    }

    /**
     * @return Tab<Recipe>
     */
    public function findByAuthorAndType(User $author, RecipeType $type): Tab
    {
        return $this->findBy(
            ['authorId' => $author->getId(), 'typeSlug' => $type->getSlug()],
            ['slug' => 'ASC']
        );
    }

    public function findBy(array $criteria, ?array $orderBy = null): Tab
    {
        $recipes = Tab::fromArray($this->entityRepository->findBy($criteria, $orderBy));
        return $recipes->map(fn (RecipeEntity $entity) => $this->convertEntityToModel($entity));
    }

    /**
     * @throws UserException
     * @throws RecipeException
     */
    private function convertEntityToModel(RecipeEntity $entity): Recipe
    {
        return Recipe::load([
            'id' => $entity->getId(),
            'name' => $entity->getName(),
            'serving' => $entity->getServing(),
            'author' => $this->userRepository->findOneById($entity->getAuthorId()),
            'type' => $this->recipeTypeRepository->findOneBySlug($entity->getTypeSlug()),
            'rows' => Tab::newEmptyTyped(RecipeRow::class), // On ne charge pas inutilement le reste
        ]);
    }
}
