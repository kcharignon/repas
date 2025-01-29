<?php

namespace Repas\Repas\Infrastructure\Repository;


use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Interface\DepartmentRepository;
use Repas\Repas\Domain\Model\Department as DepartmentModel;
use Repas\Repas\Infrastructure\Entity\Department as DepartmentEntity;
use Repas\Shared\Infrastructure\Repository\ModelCache;

class DepartmentPostgreSQLRepository extends ServiceEntityRepository implements DepartmentRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private ModelCache $modelCache,
    ) {
        parent::__construct($registry, DepartmentEntity::class);
    }

    /**
     * @throws DepartmentException
     */
    public function getOneBySlug(string $slug): DepartmentModel
    {
        // On cherche dans le cache
        if (($model = $this->modelCache->getModelCache(DepartmentModel::class, $slug)) !== null) {
            return $model;
        }

        // On cherche en BDD
        if (($entity = $this->find($slug)) !== null) {
            $model = $this->convertEntityToModel($entity);

            // On stock dans le cache
            $this->modelCache->setModelCache($model);
            return $model;
        }

        throw DepartmentException::notFound();
    }

    public function save(DepartmentModel $department): void
    {
        $this->modelCache->removeModelCache($department);
        $departmentEntity = $this->find($department->getSlug());
        if ($departmentEntity) {
            $this->updateEntity($departmentEntity, $department);
        } else {
            $departmentEntity = DepartmentEntity::fromModel($department);
        }

        $this->getEntityManager()->persist($departmentEntity);
        $this->getEntityManager()->flush();
        $this->modelCache->setModelCache($department);
    }

    private function updateEntity(DepartmentEntity $departmentEntity, DepartmentModel $department): void
    {
        $departmentEntity->setName($department->getName())
            ->setImage($department->getImage());
    }

    private function convertEntityToModel(DepartmentEntity $departmentEntity): DepartmentModel
    {
        return DepartmentModel::load([
            'slug' => $departmentEntity->getSlug(),
            'name' => $departmentEntity->getName(),
            'image' => $departmentEntity->getImage(),
        ]);
    }
}
