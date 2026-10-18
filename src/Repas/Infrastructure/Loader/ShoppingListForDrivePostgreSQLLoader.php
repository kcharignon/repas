<?php

namespace Repas\Repas\Infrastructure\Loader;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Repas\Infrastructure\Entity\Ingredient;
use Repas\Repas\Infrastructure\Entity\ShoppingList;
use Repas\Repas\Infrastructure\Entity\ShoppingListRow;
use Repas\Repas\Infrastructure\Entity\Unit;
use Repas\Shared\Application\Dto\ShoppingListForDrive;
use Repas\Shared\Application\Dto\ShoppingListLineForDrive;
use Repas\Shared\Application\Interface\ShoppingListForDriveLoader;
use Repas\Shared\Domain\Model\Dimension;

final readonly class ShoppingListForDrivePostgreSQLLoader implements ShoppingListForDriveLoader
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function get(string $shoppingListId): ShoppingListForDrive
    {
        $list = $this->entityManager->createQueryBuilder()
            ->select('l.id AS id', 'l.ownerId AS ownerId', 'l.status AS status', 'l.version AS version')
            ->from(ShoppingList::class, 'l')
            ->where('l.id = :id')
            ->setParameter('id', $shoppingListId)
            ->getQuery()
            ->getOneOrNullResult();

        if ($list === null) {
            throw ShoppingListException::shoppingListNotFound($shoppingListId);
        }

        /** @var ShoppingListStatus $status */
        $status = $list['status'];

        return new ShoppingListForDrive(
            id: $list['id'],
            ownerId: $list['ownerId'],
            status: $status->value,
            version: $list['version'],
            lines: $this->loadLines($shoppingListId),
        );
    }

    /**
     * Jointures internes, comme `ShoppingListPostgreSQLLoader` : une ligne dont l'ingrédient ou l'unité a disparu est ignorée.
     * Tri PHP par slug d'ingrédient (la collation PostgreSQL ordonne autrement les slugs à tirets).
     *
     * @return list<ShoppingListLineForDrive>
     */
    private function loadLines(string $shoppingListId): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select(
                'r.quantity AS quantity',
                'i.slug AS ingredientSlug', 'i.name AS ingredientName',
                'u.slug AS unitSlug', 'u.name AS unitName', 'u.symbol AS unitSymbol', 'u.dimension AS unitDimension', 'u.baseFactor AS unitBaseFactor',
            )
            ->from(ShoppingListRow::class, 'r')
            ->innerJoin(Ingredient::class, 'i', Join::WITH, 'i.slug = r.ingredientSlug')
            ->innerJoin(Unit::class, 'u', Join::WITH, 'u.slug = r.unitSlug')
            ->where('r.shoppingListId = :shoppingListId')
            ->setParameter('shoppingListId', $shoppingListId)
            ->getQuery()
            ->getArrayResult();

        usort($rows, static fn(array $a, array $b): int => $a['ingredientSlug'] <=> $b['ingredientSlug']);

        return array_map(
            static function (array $row): ShoppingListLineForDrive {
                /** @var ?Dimension $dimension */
                $dimension = $row['unitDimension'];

                return new ShoppingListLineForDrive(
                    ingredientSlug: $row['ingredientSlug'],
                    ingredientName: $row['ingredientName'],
                    quantity: (float) $row['quantity'],
                    unitSlug: $row['unitSlug'],
                    unitName: $row['unitName'],
                    unitSymbol: $row['unitSymbol'],
                    unitDimension: $dimension?->value,
                    unitBaseFactor: $row['unitBaseFactor'] === null ? null : (float) $row['unitBaseFactor'],
                );
            },
            $rows,
        );
    }
}
