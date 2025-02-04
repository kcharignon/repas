<?php

namespace Repas\Repas\Infrastructure\Repository;


use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Interface\DepartmentRepository;
use Repas\Repas\Domain\Model\Department as DepartmentModel;
use Repas\Repas\Infrastructure\Entity\Department as DepartmentEntity;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Infrastructure\Repository\ModelCache;

readonly class DepartmentPostgreSQLRepository extends PostgreSQLRepository implements DepartmentRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private ModelCache $modelCache,
    ) {
        parent::__construct($registry, DepartmentEntity::class);
    }

    public function findAll(): Tab
    {
        $entities = new Tab($this->entityRepository->findBy([], ['slug' => 'ASC']), DepartmentEntity::class);
        return $entities->map(function (DepartmentEntity $entity) {
            if (($model = $this->modelCache->getModelCache(DepartmentModel::class, $entity->getSlug())) !== null) {
                return $model;
            }

            $model = $this->convertEntityToModel($entity);
            $this->modelCache->setModelCache($model);
            return $model;
        });
    }


    /**
     * @throws DepartmentException
     */
    public function findOneBySlug(string $slug): DepartmentModel
    {
        // On cherche dans le cache
        if (($model = $this->modelCache->getModelCache(DepartmentModel::class, $slug)) !== null) {
            return $model;
        }

        // On cherche en BDD
        if (($entity = $this->entityRepository->find($slug)) !== null) {
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
        $departmentEntity = $this->entityRepository->find($department->getSlug());
        if ($departmentEntity) {
            $this->updateEntity($departmentEntity, $department);
        } else {
            $departmentEntity = DepartmentEntity::fromModel($department);
        }

        $this->entityManager->persist($departmentEntity);
        $this->entityManager->flush();
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

    /**
     * @param Tab<string> $slugs
     * @return Tab<DepartmentModel>
     */
    public function findBySlugs(Tab $slugs): Tab
    {

        // Si on ne trouve aucun element qui n'est pas en cache
        if ($slugs->find(fn(string $slug) => !$this->modelCache->isCachedExists(DepartmentModel::class, $slug)) === null) {
            return $slugs->map(fn(string $slug) => $this->modelCache->getModelCache(DepartmentModel::class, $slug));
        }

        /** @var DepartmentEntity[] $departmentsEntities */
        $departmentsEntities = $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(DepartmentEntity::class, 'd')
            ->where('d.slug IN (:slugs)')
            ->setParameter('slugs', $slugs->toArray())
            ->getQuery()
            ->getResult();

        $models = Tab::newEmptyTyped(DepartmentModel::class);
        foreach ($departmentsEntities as $departmentEntity) {
            if (($model = $this->modelCache->getModelCache(DepartmentModel::class, $departmentEntity->getSlug())) !== null) {
                $models[] = $model;
            } else {
                $model = $this->convertEntityToModel($departmentEntity);
                $this->modelCache->setModelCache($model);
                $models[] = $model;
            }
        }

        return $models;
    }
}
