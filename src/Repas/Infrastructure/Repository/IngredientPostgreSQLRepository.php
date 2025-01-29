<?php

namespace Repas\Repas\Infrastructure\Repository;


use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\DepartmentRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\Ingredient as IngredientModel;
use Repas\Repas\Infrastructure\Entity\Ingredient as IngredientEntity;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Infrastructure\Repository\ModelCache;

class IngredientPostgreSQLRepository extends ServiceEntityRepository implements IngredientRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
        private readonly ModelCache $modelCache,
        private readonly UnitRepository $unitRepository,
        private readonly DepartmentRepository $departmentRepository,
    ) {
        parent::__construct($managerRegistry, IngredientEntity::class);
    }

    /**
     * @throws IngredientException
     */
    public function findBySlug(string $slug): IngredientModel
    {
        // On cherche das le cache
        if (($model = $this->modelCache->getModelCache(IngredientModel::class, $slug)) !== null) {
            return $model;
        }

        // On cherche en base de donnée
        if (($entity = $this->findOneBy(['slug' => $slug])) !== null) {
            $model = $this->convertEntityToModel($entity);
            // On stock en cache
            $this->modelCache->setModelCache($model);
            return $model;
        }

        throw IngredientException::notFound();
    }

    public function findByDepartment(string $department): Tab
    {
        throw new \Exception('Not implemented');
    }

    public function save(IngredientModel $ingredient): void
    {
        $this->modelCache->removeModelCache($ingredient);
        $ingredientEntity = $this->find($ingredient->getSlug());
        if ($ingredientEntity) {
            $this->updateEntity($ingredientEntity, $ingredient);
        } else {
            $ingredientEntity = IngredientEntity::fromModel($ingredient);
        }

        $this->getEntityManager()->persist($ingredientEntity);
        $this->getEntityManager()->flush();
        $this->modelCache->setModelCache($ingredient);
    }

    private function updateEntity(IngredientEntity $ingredientEntity, IngredientModel $ingredient): void
    {
        $ingredientEntity
            ->setName($ingredient->getName())
            ->setImage($ingredient->getImage())
            ->setDepartmentSlug($ingredient->getDepartment()->getSlug())
            ->setDefaultCookingUnitSlug($ingredient->getDefaultCookingUnit()->getSlug())
            ->setDefaultPurchaseUnitSlug($ingredient->getDefaultPurchaseUnit()->getSlug())
        ;
    }

    /**
     * @throws IngredientException
     */
    private function convertEntityToModel(IngredientEntity $ingredientEntity): IngredientModel
    {
        try {
            return IngredientModel::load([
                "slug" => $ingredientEntity->getSlug(),
                "name" => $ingredientEntity->getName(),
                "image" => $ingredientEntity->getImage(),
                "department" => $this->departmentRepository->findBySlug($ingredientEntity->getDepartmentSlug()),
                "default_cooking_unit" => $this->unitRepository->findBySlug($ingredientEntity->getDefaultCookingUnitSlug()),
                "default_purchase_unit" => $this->unitRepository->findBySlug($ingredientEntity->getDefaultPurchaseUnitSlug()),
            ]);
        } catch (DepartmentException|UnitException) {
            throw IngredientException::subModelNotFound();
        }
    }
}
