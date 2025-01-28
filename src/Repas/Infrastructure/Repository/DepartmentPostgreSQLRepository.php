<?php

namespace Repas\Repas\Infrastructure\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Interface\DepartmentRepository;
use Repas\Repas\Domain\Model\Department;
use Repas\Repas\Infrastructure\Entity\Department as DepartmentEntity;

class DepartmentPostgreSQLRepository extends ServiceEntityRepository implements DepartmentRepository
{
    public function __construct(
        ManagerRegistry $registry,
    ) {
        parent::__construct($registry, DepartmentEntity::class);
    }

    /**
     * @throws DepartmentException
     */
    public function findBySlug(string $slug): Department
    {
        return $this->find($slug)?->getModel()
            ?? throw DepartmentException::notFound()
        ;
    }

    public function save(Department $department): void
    {
        $departmentEntity = $this->find($department->getSlug());
        if ($departmentEntity) {
            $this->updateEntity($departmentEntity, $department);
        } else {
            $departmentEntity = DepartmentEntity::fromModel($department);
        }

        $this->getEntityManager()->persist($departmentEntity);
        $this->getEntityManager()->flush();
    }

    private function updateEntity(DepartmentEntity $departmentEntity, Department $department): void
    {
        $departmentEntity->setName($department->getName())
            ->setImage($department->getImage());
    }
}
