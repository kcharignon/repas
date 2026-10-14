<?php

namespace Repas\Repas\Infrastructure\Repository;


use Repas\Shared\Infrastructure\Repository\PostgreSQLRepository;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Exception\ShoppingListRowException;
use Repas\Repas\Domain\Interface\ShoppingListRowRepository;
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
        ]);
    }
}
