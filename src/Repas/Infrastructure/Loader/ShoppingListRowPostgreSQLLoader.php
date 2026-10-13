<?php

namespace Repas\Repas\Infrastructure\Loader;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Repas\Repas\Application\Loader\ShoppingListRowLoader;
use Repas\Repas\Application\Query\GetShoppingList\ShoppingListRowDTO;
use Repas\Repas\Domain\Exception\ShoppingListRowException;
use Repas\Repas\Infrastructure\Entity\Ingredient;
use Repas\Repas\Infrastructure\Entity\ShoppingListRow;
use Repas\Repas\Infrastructure\Entity\Unit;

final readonly class ShoppingListRowPostgreSQLLoader implements ShoppingListRowLoader
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function get(string $rowId): ShoppingListRowDTO
    {
        $row = $this->entityManager->createQueryBuilder()
            ->select('r.id AS id', 'r.quantity AS quantity', 'r.checked AS checked', 'i.name AS ingredientName', 'u.symbol AS unitSymbol')
            ->from(ShoppingListRow::class, 'r')
            ->innerJoin(Ingredient::class, 'i', Join::WITH, 'i.slug = r.ingredientSlug')
            ->innerJoin(Unit::class, 'u', Join::WITH, 'u.slug = r.unitSlug')
            ->where('r.id = :id')
            ->setParameter('id', $rowId)
            ->getQuery()
            ->getOneOrNullResult();

        if ($row === null) {
            throw ShoppingListRowException::notFound($rowId);
        }

        return new ShoppingListRowDTO($row['id'], $row['ingredientName'], (float) $row['quantity'], $row['unitSymbol'], (bool) $row['checked']);
    }
}
