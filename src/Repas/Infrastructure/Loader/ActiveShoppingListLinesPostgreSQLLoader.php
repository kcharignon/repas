<?php

namespace Repas\Repas\Infrastructure\Loader;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Repas\Repas\Application\Loader\ActiveShoppingListLines;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Repas\Infrastructure\Entity\Department;
use Repas\Repas\Infrastructure\Entity\Ingredient;
use Repas\Repas\Infrastructure\Entity\ShoppingList;
use Repas\Repas\Infrastructure\Entity\ShoppingListRow;
use Repas\Repas\Infrastructure\Entity\Unit;
use Repas\Shared\Application\Dto\ListedLine;

/**
 * Les lignes de la liste active de l'utilisateur en deux requêtes : l'identifiant de la liste active (aucune : `null`), puis ses lignes avec le
 * nom de l'ingrédient, son rayon et le nom de l'unité de la ligne. Les lignes d'une liste sont celles de l'utilisateur : aucun contrôle de
 * visibilité des ingrédients à refaire.
 */
final readonly class ActiveShoppingListLinesPostgreSQLLoader implements ActiveShoppingListLines
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function forUser(string $userId): ?array
    {
        $lists = $this->entityManager->createQueryBuilder()
            ->select('l.id AS id')
            ->from(ShoppingList::class, 'l')
            ->where('l.ownerId = :owner')
            ->andWhere('l.status = :status')
            ->setParameter('owner', $userId)
            ->setParameter('status', ShoppingListStatus::ACTIVE)
            ->setMaxResults(1)
            ->getQuery()
            ->getArrayResult();
        if ($lists === []) {
            return null;
        }

        /** @var list<array{slug: string, name: string, departmentSlug: string, departmentName: string, quantity: float|int|string, unitSlug: string, unitName: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('r.ingredientSlug AS slug', 'i.name AS name', 'd.slug AS departmentSlug', 'd.name AS departmentName', 'r.quantity AS quantity', 'u.slug AS unitSlug', 'u.name AS unitName')
            ->from(ShoppingListRow::class, 'r')
            ->innerJoin(Ingredient::class, 'i', Join::WITH, 'i.slug = r.ingredientSlug')
            ->innerJoin(Department::class, 'd', Join::WITH, 'd.slug = i.departmentSlug')
            ->innerJoin(Unit::class, 'u', Join::WITH, 'u.slug = r.unitSlug')
            ->where('r.shoppingListId = :list')
            ->setParameter('list', $lists[0]['id'])
            ->orderBy('r.ingredientSlug')
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn(array $row): ListedLine => new ListedLine($row['slug'], $row['name'], $row['departmentSlug'], $row['departmentName'], (float) $row['quantity'], $row['unitSlug'], $row['unitName']),
            $rows,
        );
    }

    public function hasSentList(string $userId): bool
    {
        return $this->entityManager->createQueryBuilder()
            ->select('l.id')
            ->from(ShoppingList::class, 'l')
            ->where('l.ownerId = :owner')
            ->andWhere('l.status = :status')
            ->setParameter('owner', $userId)
            ->setParameter('status', ShoppingListStatus::SENT)
            ->setMaxResults(1)
            ->getQuery()
            ->getArrayResult() !== [];
    }
}
