<?php

namespace Repas\Repas\Infrastructure\Repository;


use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Infrastructure\Entity\Recipe as RecipeEntity;
use Repas\Repas\Infrastructure\Entity\Unit as UnitEntity;
use Repas\Shared\Infrastructure\Repository\ModelCache;
use Repas\User\Domain\Interface\UserRepository;

class RecipePostgreSQLRepository  extends ServiceEntityRepository implements RecipeRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
        private readonly ModelCache $modelCache,
        private readonly UserRepository $userRepository,
        private readonly RecipeTypeRepository $recipeTypeRepository,
    ) {
        parent::__construct($managerRegistry, UnitEntity::class);
    }

    /**
     * @throws RecipeException
     */
    public function findOneById(string $id): Recipe
    {
        if (($model = $this->modelCache->getModelCache(Recipe::class, $id)) === null) {
            return $model;
        }

        if (($entity = $this->find($id)) === null) {
            $model = $this->convertEntityToModel($entity);
            $this->modelCache->setModelCache($model);
            return $model;
        }

        throw RecipeException::notFound();
    }




    public function save(Recipe $recipe): void
    {
        $recipeEntity = $this->find($recipe->getId());
        if ($recipeEntity === null) {
            $recipeEntity = RecipeEntity::fromModel($recipe);
            $this->getEntityManager()->persist($recipeEntity);
        } else {
            $recipeEntity->updateFromModel($recipe);
        }

        $this->getEntityManager()->flush();
    }

    private function convertEntityToModel(RecipeEntity $entity): Recipe
    {
        return Recipe::load([
            'id' => $entity->getId(),
            'name' => $entity->getName(),
            'serving' => $entity->getServing(),
            'author' => $this->userRepository->findOneById($entity->getAuthorId()),
            'type' => $this->recipeTypeRepository->findOneBySlug($entity->getTypeSlug()),
        ]);
    }
}
