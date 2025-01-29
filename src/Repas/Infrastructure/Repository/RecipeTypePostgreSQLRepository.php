<?php

namespace Repas\Repas\Infrastructure\Repository;

use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeType as RecipeTypeModel;
use Repas\Repas\Infrastructure\Entity\RecipeType as RecipeTypeEntity;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Infrastructure\Repository\ModelCache;

readonly class RecipeTypePostgreSQLRepository extends PostgreSQLRepository implements RecipeTypeRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
        private ModelCache $modelCache,
    ) {
        parent::__construct($managerRegistry, RecipeTypeEntity::class);
    }

    /**
     * @return Tab<RecipeTypeModel>
     */
    public function getAll(): Tab
    {
        return Tab::fromArray($this->entityRepository->findBy([], ['sequence' => 'ASC']))
            ->map(function (RecipeTypeEntity $entity) {
                if (($model = $this->modelCache->getModelCache(RecipeTypeModel::class, $entity->getSlug())) !== null) {
                    return $model;
                }

                $model = $this->convertEntityToModel($entity);
                $this->modelCache->setModelCache($model);
                return $model;
            });
    }

    /**
     * @throws RecipeException
     */
    public function getOneBySlug(string $slug): RecipeTypeModel
    {
        if (($model = $this->modelCache->getModelCache(Recipe::class, $slug)) !== null) {
            return $model;
        }

        if (($entity = $this->entityRepository->find($slug)) !== null) {
            $model = $this->convertEntityToModel($entity);
            $this->modelCache->setModelCache($model);
            return $model;
        }

        throw RecipeException::typeNotFound();
    }

    private function convertEntityToModel(RecipeTypeEntity $entity): RecipeTypeModel
    {
        return RecipeTypeModel::load([
            'slug' => $entity->getSlug(),
            'name' => $entity->getName(),
            'image' => $entity->getImage(),
            'order' => $entity->getSequence(),
        ]);
    }
}
