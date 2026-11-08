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
use Repas\Repas\Infrastructure\Entity\Ingredient;
use Repas\Repas\Infrastructure\Entity\ShoppingListType;
use Repas\Repas\Infrastructure\Entity\Unit;
use Repas\Repas\Infrastructure\Visibility\IngredientVisibility;

final readonly class DepartmentPostgreSQLLoader implements DepartmentLoader
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function list(string $userId): array
    {
        // Le type de liste du rayon pour CE compte, s'il existe encore (un rattachement périmé se lit « pas de type »), et le nombre
        // d'ingrédients que CE compte voit dans le rayon : les mêmes lignes que l'écran d'un rayon (`IngredientPostgreSQLLoader::listByDepartment` :
        // règle `IngredientVisibility::visibleBy`, unité d'achat existante, parties comprises), comptées par un GROUP BY de la même instruction
        // (jamais un COUNT corrélé par rayon : le nombre de requêtes et leur coût ne dépendent pas du nombre de rayons).
        $rows = $this->entityManager->createQueryBuilder()
            ->select('d.slug AS slug', 'd.name AS name', 'd.image AS image', 'd.icon AS icon', 't.id AS listTypeId', 'COUNT(pu.slug) AS ingredientCount')
            ->from(Department::class, 'd')
            ->leftJoin(DepartmentListType::class, 'dlt', Join::WITH, 'dlt.departmentSlug = d.slug AND dlt.ownerId = :user')
            ->leftJoin(ShoppingListType::class, 't', Join::WITH, 't.id = dlt.listTypeId AND t.ownerId = :user')
            ->leftJoin(Ingredient::class, 'i', Join::WITH, 'i.departmentSlug = d.slug AND ' . IngredientVisibility::visibleBy('i', 'user'))
            ->leftJoin(Unit::class, 'pu', Join::WITH, 'pu.slug = i.defaultPurchaseUnitSlug')
            ->setParameter('user', $userId)
            ->groupBy('d.slug', 'd.name', 'd.image', 'd.icon', 't.id')
            ->orderBy('d.slug', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): DepartmentListItemDTO => new DepartmentListItemDTO($row['slug'], $row['name'], $row['image'], DepartmentIcon::known($row['icon']), $row['listTypeId'], (int) $row['ingredientCount']),
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
