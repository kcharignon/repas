<?php

namespace Repas\Repas\Infrastructure\Loader;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Repas\Repas\Application\Loader\DepartmentLoader;
use Repas\Repas\Application\Query\ListDepartments\DepartmentDTO;
use Repas\Repas\Application\Query\ListDepartments\DepartmentListItemDTO;
use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Model\DepartmentIcon;
use Repas\Repas\Infrastructure\Entity\Department;
use Repas\Repas\Infrastructure\Entity\DepartmentListType;
use Repas\Repas\Infrastructure\Entity\ShoppingListType;

final readonly class DepartmentPostgreSQLLoader implements DepartmentLoader
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function list(string $userId): array
    {
        // Le type de liste du rayon pour CE compte, s'il existe encore (un rattachement périmé se lit « pas de type »)
        $rows = $this->entityManager->createQueryBuilder()
            ->select('d.slug AS slug', 'd.name AS name', 'd.image AS image', 'd.icon AS icon', 't.id AS listTypeId')
            ->from(Department::class, 'd')
            ->leftJoin(DepartmentListType::class, 'dlt', Join::WITH, 'dlt.departmentSlug = d.slug AND dlt.ownerId = :user')
            ->leftJoin(ShoppingListType::class, 't', Join::WITH, 't.id = dlt.listTypeId AND t.ownerId = :user')
            ->setParameter('user', $userId)
            ->orderBy('d.slug', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): DepartmentListItemDTO => new DepartmentListItemDTO($row['slug'], $row['name'], $row['image'], DepartmentIcon::known($row['icon']), $row['listTypeId']),
            $rows,
        );
    }

    public function get(string $slug): DepartmentDTO
    {
        $row = $this->entityManager->createQueryBuilder()
            ->select('d.slug AS slug', 'd.name AS name', 'd.image AS image', 'd.icon AS icon')
            ->from(Department::class, 'd')
            ->where('d.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();

        if ($row === null) {
            throw DepartmentException::notFound();
        }

        return new DepartmentDTO($row['slug'], $row['name'], $row['image'], DepartmentIcon::known($row['icon']));
    }
}
