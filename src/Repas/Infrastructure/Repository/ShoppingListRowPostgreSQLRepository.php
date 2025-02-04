<?php

namespace Repas\Repas\Infrastructure\Repository;


use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\ShoppingListRowException;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\ShoppingListRowRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\ShoppingListRow;
use Repas\Repas\Domain\Model\ShoppingListRow as ShoppingListRowModel;
use Repas\Repas\Infrastructure\Entity\ShoppingListRow as ShoppingListRowEntity;
use Repas\Shared\Domain\Tool\Tab;

readonly class ShoppingListRowPostgreSQLRepository extends PostgreSQLRepository implements ShoppingListRowRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
        private IngredientRepository $ingredientRepository,
        private UnitRepository $unitRepository,
    ) {
        parent::__construct($managerRegistry, ShoppingListRowEntity::class);
    }

    /**
     * @throws UnitException
     * @throws IngredientException
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

    /**
     * @throws UnitException
     * @throws IngredientException
     */
    private function convertEntityToModel(ShoppingListRowEntity $rowEntity): ShoppingListRowModel
    {
        return ShoppingListRowModel::load([
            'id' => $rowEntity->getId(),
            'shopping_list_id' => $rowEntity->getShoppingListId(),
            'ingredient' => $this->ingredientRepository->findOneBySlug($rowEntity->getIngredientSlug()),
            'quantity' => $rowEntity->getQuantity(),
            'unit' => $this->unitRepository->findOneBySlug($rowEntity->getUnitSlug()),
            'checked' => $rowEntity->isChecked(),
        ]);
    }
}
