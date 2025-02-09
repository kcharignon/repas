<?php

namespace Repas\Repas\Infrastructure\Repository;


use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\ShoppingListIngredient;
use Repas\Repas\Domain\Model\ShoppingListIngredient as ShopListIngModel;
use Repas\Repas\Infrastructure\Entity\ShoppingListIngredient as ShopListIngEntity;
use Repas\Shared\Domain\Tool\Tab;

readonly class ShoppingListIngredientPostgreSQLRepository extends PostgreSQLRepository
{

    public function __construct(
        ManagerRegistry $registry,
        private IngredientRepository $ingredientRepository,
        private UnitRepository $unitRepository,
    ) {
        parent::__construct($registry, ShopListIngEntity::class);
    }

    /**
     * @param string $shoppingListId
     * @return Tab<ShopListIngModel>
     */
    public function findByShoppingListId(string $shoppingListId): Tab
    {
        return new Tab($this->entityRepository->findBy(['shoppingListId' => $shoppingListId]), ShopListIngEntity::class)
            ->map(fn (ShopListIngEntity $entity) => $this->convertEntityToModel($entity));
    }

    public function save(ShoppingListIngredient $shoppingListIngredient): void
    {
        $entity = $this->entityRepository->find($shoppingListIngredient->getId());
        if ($entity instanceof ShopListIngEntity) {
            $entity->updateFromModel($shoppingListIngredient);
        } else {
            $entity = ShopListIngEntity::fromModel($shoppingListIngredient);
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
    }

    /**
     * @throws UnitException
     * @throws IngredientException
     */
    private function convertEntityToModel(ShopListIngEntity $entity): ShopListIngModel
    {
        return ShopListIngModel::load([
            'id' => $entity->getId(),
            'shopping_list_id' => $entity->getShoppingListId(),
            'ingredient' => $this->ingredientRepository->findOneBySlug($entity->getIngredientSlug()),
            'quantity' => $entity->getQuantity(),
            'unit' => $this->unitRepository->findOneBySlug($entity->getUnitSlug()),
        ]);
    }

    /**
     * @param Tab<string> $ids
     */
    public function deleteByShoppingListIdExceptIds(string $shoppingListId, Tab $ids): void
    {
        $qb = $this->entityRepository->createQueryBuilder('sli')
            ->delete()
            ->where('sli.shoppingListId = :shoppingListId')
            ->setParameter('shoppingListId', $shoppingListId);

        if ($ids->count() > 0) {
            $qb->andWhere('sli.id not in (:ids)')
                ->setParameter('ids', $ids->toArray());
        }

        $qb->getQuery()
            ->execute();
    }

    public function deleteByShoppingListId(string $shoppingListId): void
    {
        $this->deleteByShoppingListIdExceptIds($shoppingListId, new Tab([]));
    }
}
