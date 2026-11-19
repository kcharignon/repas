<?php

namespace Repas\Repas\Infrastructure\Repository;


use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Repas\Shared\Infrastructure\Repository\PostgreSQLRepository;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Exception\ShoppingListRowException;
use Repas\Repas\Domain\Interface\ShoppingListRowRepository;
use Repas\Repas\Domain\Model\DriveLineOutcome;
use Repas\Repas\Domain\Model\RowCheckSource;
use Repas\Repas\Domain\Model\ShoppingListRow;
use Repas\Repas\Domain\Model\ShoppingListRow as ShoppingListRowModel;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Repas\Infrastructure\Entity\ShoppingListRow as ShoppingListRowEntity;
use Repas\Shared\Domain\Tool\Tab;

readonly class ShoppingListRowPostgreSQLRepository extends PostgreSQLRepository implements ShoppingListRowRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
    ) {
        parent::__construct($managerRegistry, ShoppingListRowEntity::class);
    }

    /**
     * @throws ShoppingListRowException
     */
    public function findOneById(string $id): ShoppingListRowModel
    {
        $entity = $this->entityRepository->find($id);
        if ($entity === null) {
            throw ShoppingListRowException::notFound($id);
        }

        return $this->convertEntityToModel($entity);
    }


    public function allRowsChecked(string $shoppingListId): bool
    {
        $unchecked = $this->entityManager->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(ShoppingListRowEntity::class, 'r')
            ->where('r.shoppingListId = :shoppingListId')
            ->andWhere('r.checked = false')
            ->setParameter('shoppingListId', $shoppingListId)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $unchecked === 0;
    }

    public function recordDrivePassage(string $shoppingListId, array $attempts, DateTimeImmutable $at): void
    {
        if ($attempts === []) {
            return;
        }

        // Une seule instruction, sans hydrater aucune ligne : l'issue de chaque ingrédient est jointe à ses lignes par une liste de valeurs, et la ligne
        // dont l'article est au panier est cochée au passage (origine DRIVE) si elle ne l'était pas ; une ligne déjà cochée garde son origine
        $table = $this->entityManager->getClassMetadata(ShoppingListRowEntity::class)->getTableName();
        $values = [];
        $params = ['list' => $shoppingListId, 'at1' => $at, 'at2' => $at];
        $types = ['at1' => Types::DATETIME_IMMUTABLE, 'at2' => Types::DATETIME_IMMUTABLE];
        foreach (array_values($attempts) as $index => $attempt) {
            $values[] = sprintf('(CAST(:s%1$d AS VARCHAR), CAST(:o%1$d AS VARCHAR), CAST(:r%1$d AS VARCHAR))', $index);
            $params['s' . $index] = $attempt->ingredientSlug;
            $params['o' . $index] = $attempt->outcome->value;
            $params['r' . $index] = $attempt->reason;
        }
        $inCart = "'" . DriveLineOutcome::IN_CART->value . "'";

        $this->entityManager->getConnection()->executeStatement(
            'UPDATE ' . $table . ' AS r SET'
            . ' drive_outcome = v.outcome, drive_reason = v.reason, drive_attempted_at = :at1,'
            . ' checked = r.checked OR v.outcome = ' . $inCart . ','
            . ' checked_by = CASE WHEN NOT r.checked AND v.outcome = ' . $inCart . " THEN '" . RowCheckSource::DRIVE->value . "' ELSE r.checked_by END,"
            . ' checked_at = CASE WHEN NOT r.checked AND v.outcome = ' . $inCart . ' THEN CAST(:at2 AS TIMESTAMP(0)) ELSE r.checked_at END'
            . ' FROM (VALUES ' . implode(', ', $values) . ') AS v(slug, outcome, reason)'
            . ' WHERE r.shopping_list = :list AND r.ingredient = v.slug',
            $params,
            $types,
        );
    }

    /**
     * @param string $shoppingListId
     * @return Tab<ShoppingListRowModel>
     */
    public function findByShoppingListId(string $shoppingListId): Tab
    {
        $entities = $this->entityRepository->findBy(['shoppingListId' => $shoppingListId]);
        return new Tab($entities, ShoppingListRowEntity::class)
            ->map(fn (ShoppingListRowEntity $rowEntity): ShoppingListRowModel => $this->convertEntityToModel($rowEntity), ShoppingListRow::class);
    }

    /**
     * @param Tab<string> $ids
     */
    public function deleteByShoppingListIdExceptIds(string $shoppingListId, Tab $ids): void
    {
        $qb = $this->entityRepository->createQueryBuilder('slr')
            ->delete()
            ->where('slr.shoppingListId = :shoppingListId')
            ->setParameter('shoppingListId', $shoppingListId);

        if ($ids->count() > 0) {
            $qb->andWhere('slr.id not in (:ids)')
                ->setParameter('ids', $ids->toArray());
        }

        $qb->getQuery()
            ->execute();
    }

    public function deleteByShoppingListId(string $shoppingListId): void
    {
        $this->deleteByShoppingListIdExceptIds($shoppingListId, new Tab([]));
    }


    /**
     * Enregistre plusieurs lignes : celles qui existent déjà sont lues en une requête, les autres sont insérées sans être
     * cherchées une à une.
     *
     * @param iterable<ShoppingListRowModel> $shoppingListRows
     */
    public function saveAll(iterable $shoppingListRows): void
    {
        $rows = [];
        foreach ($shoppingListRows as $shoppingListRow) {
            $rows[] = $shoppingListRow;
        }
        $this->preload(array_map(static fn(ShoppingListRowModel $row): string => $row->getId(), $rows), 'id');

        foreach ($rows as $shoppingListRow) {
            $entity = $this->findManaged($shoppingListRow->getId());
            if ($entity instanceof ShoppingListRowEntity) {
                $entity->updateFromModel($shoppingListRow);
            } else {
                $this->entityManager->persist(ShoppingListRowEntity::fromModel($shoppingListRow));
            }
        }

        $this->entityManager->flush();
    }

    public function save(ShoppingListRowModel $shoppingListRow): void
    {
        $entity = $this->entityRepository->find($shoppingListRow->getId());
        if ($entity instanceof ShoppingListRowEntity) {
            $entity->updateFromModel($shoppingListRow);
        } else {
            $entity = ShoppingListRowEntity::fromModel($shoppingListRow);
            $this->entityManager->persist($entity);
        }

        $this->entityManager->flush();
    }

    private function convertEntityToModel(ShoppingListRowEntity $rowEntity): ShoppingListRowModel
    {
        return ShoppingListRowModel::load([
            'id' => $rowEntity->getId(),
            'shopping_list_id' => $rowEntity->getShoppingListId(),
            'ingredient_slug' => $rowEntity->getIngredientSlug(),
            'quantity' => $rowEntity->getQuantity(),
            'unit_slug' => $rowEntity->getUnitSlug(),
            'checked' => $rowEntity->isChecked(),
            'checked_by' => $rowEntity->getCheckedBy(),
            'checked_at' => $rowEntity->getCheckedAt(),
        ]);
    }
}
