<?php

namespace Repas\Repas\Infrastructure\Loader;

use Doctrine\ORM\EntityManagerInterface;
use Repas\Repas\Application\Loader\DepartmentLoader;
use Repas\Repas\Application\Query\ListDepartments\DepartmentDTO;
use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Infrastructure\Entity\Department;

final readonly class DepartmentPostgreSQLLoader implements DepartmentLoader
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function list(): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('d.slug AS slug', 'd.name AS name', 'd.image AS image')
            ->from(Department::class, 'd')
            ->orderBy('d.slug', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): DepartmentDTO => new DepartmentDTO($row['slug'], $row['name'], $row['image']),
            $rows,
        );
    }

    public function get(string $slug): DepartmentDTO
    {
        $row = $this->entityManager->createQueryBuilder()
            ->select('d.slug AS slug', 'd.name AS name', 'd.image AS image')
            ->from(Department::class, 'd')
            ->where('d.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();

        if ($row === null) {
            throw DepartmentException::notFound();
        }

        return new DepartmentDTO($row['slug'], $row['name'], $row['image']);
    }
}
