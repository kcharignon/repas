<?php

namespace Repas\Repas\Infrastructure\Repository;


use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Interface\DepartmentRepository;
use Repas\Repas\Domain\Model\Department as DepartmentModel;
use Repas\Repas\Infrastructure\Entity\Department as DepartmentEntity;
use Repas\Shared\Domain\Tool\Tab;

readonly class DepartmentPostgreSQLRepository extends PostgreSQLRepository implements DepartmentRepository
{
    public function __construct(
        ManagerRegistry $registry,
    ) {
        parent::__construct($registry, DepartmentEntity::class);
    }

    public function findAll(): Tab
    {
        $entities = new Tab($this->entityRepository->findBy([], ['slug' => 'ASC']), DepartmentEntity::class);
        return $entities->map(fn(DepartmentEntity $entity) => $this->convertEntityToModel($entity));
    }


    /**
     * @throws DepartmentException
     */
    public function findOneBySlug(string $slug): DepartmentModel
    {
        if (($entity = $this->entityRepository->find($slug)) !== null) {
            return $this->convertEntityToModel($entity);
        }

        throw DepartmentException::notFound();
    }

    public function save(DepartmentModel $department): void
    {
        $departmentEntity = $this->entityRepository->find($department->getSlug());
        if ($departmentEntity) {
            $this->updateEntity($departmentEntity, $department);
        } else {
            $departmentEntity = DepartmentEntity::fromModel($department);
        }

        $this->entityManager->persist($departmentEntity);
        $this->entityManager->flush();
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
        // Les slugs absents de l'identity map sont lus en une requête, puis résolus un par un
        $this->preload($slugs);

        $models = Tab::newEmptyTyped(DepartmentModel::class);
        foreach ($slugs as $slug) {
            $models[] = $this->findOneBySlug($slug);
        }

        return $models;
    }
}
