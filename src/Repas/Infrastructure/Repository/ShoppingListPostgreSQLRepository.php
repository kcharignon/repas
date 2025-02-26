<?php

namespace Repas\Repas\Infrastructure\Repository;


use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Exception\MealException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Meal as MealModel;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListIngredient as ShoppingListIngredientModel;
use Repas\Repas\Domain\Model\ShoppingListRow as ShoppingListRowModel;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Repas\Infrastructure\Entity\Recipe as RecipeEntity;
use Repas\Repas\Infrastructure\Entity\ShoppingList as ShoppingListEntity;
use Repas\Repas\Infrastructure\Entity\ShoppingListIngredient;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Infrastructure\Repository\ModelCache;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;

readonly class ShoppingListPostgreSQLRepository extends PostgreSQLRepository implements ShoppingListRepository
{
    public function __construct(
        ManagerRegistry                                    $managerRegistry,
        private ModelCache                                 $modelCache,
        private UserRepository                             $userRepository,
        private MealPostgreSQLRepository                   $mealRepository,
        private ShoppingListIngredientPostgreSQLRepository $shopListIngredientRepository,
        private ShoppingListRowPostgreSQLRepository        $shopListRowRepository,
    ) {
        parent::__construct($managerRegistry, ShoppingListEntity::class);
    }

    /**
     * @return Tab<ShoppingList>
     */
    public function findByOwner(User $owner): Tab
    {
        $shoppingListEntities = Tab::fromArray($this->entityRepository->findBy(['ownerId' => $owner->getId()], ['createdAt' => 'DESC']));
        return $this->convertEntitiesToModels($shoppingListEntities);
    }

    /**
     * @throws ShoppingListException
     * @throws UserException
     */
    public function findOneById(string $id): ShoppingList
    {
        $shoppingListEntity = $this->entityRepository->find($id);

        if (null === $shoppingListEntity) {
            throw ShoppingListException::shoppingListNotFound($id);
        }

        return $this->convertEntityToModel($shoppingListEntity);
    }

    /**
     * @throws ShoppingListException
     * @throws MealException
     * @throws UserException
     */
    public function findOneByMealId(string $mealId): ShoppingList
    {
        $meal = $this->mealRepository->findOneById($mealId);
        return $this->findOneById($meal->getShoppingListId());
    }


    public function save(ShoppingList $shoppingList): void
    {
        $this->modelCache->removeModelCache($shoppingList);
        $shoppingListEntity = $this->entityRepository->find($shoppingList->getId());
        if (!$shoppingListEntity instanceof ShoppingListEntity) {
            $shoppingListEntity = ShoppingListEntity::fromModel($shoppingList);
            $this->entityManager->persist($shoppingListEntity);
        } else {
            $shoppingListEntity->updateFromModel($shoppingList);
            // Suppression des anciens repas
            $this->mealRepository->deleteByShoppingListIdExceptIds(
                $shoppingListEntity->getId(),
                $shoppingList->getMeals()->map(fn(MealModel $meal): string => $meal->getId())
            );

            // Suppression des anciens ingredients
            $this->shopListIngredientRepository->deleteByShoppingListIdExceptIds(
                $shoppingListEntity->getId(),
                $shoppingList->getIngredients()->map(fn(ShoppingListIngredientModel $ingredient): string => $ingredient->getId())
            );

            // Suppression des anciennes lignes de course
            $this->shopListRowRepository->deleteByShoppingListIdExceptIds(
                $shoppingListEntity->getId(),
                $shoppingList->getRows()->map(fn(ShoppingListRowModel $row): string => $row->getId())
            );
        }
        // Ajoute des nouveaux repas et mise à jour des autres
        foreach ($shoppingList->getMeals() as $meal) {
            $this->mealRepository->save($meal);
        }
        // Ajoute des nouveaux ingredients et mise à jour des autres
        foreach ($shoppingList->getIngredients() as $shopListIngredient) {
            $this->shopListIngredientRepository->save($shopListIngredient);
        }
        // Ajoute de nouvelles lignes et mise à jour des autres
        foreach ($shoppingList->getRows() as $shoppingListRow) {
            $this->shopListRowRepository->save($shoppingListRow);
        }

        $this->entityManager->flush();
        $this->modelCache->setModelCache($shoppingList);
    }

    /**
     * @throws UserException
     */
    public function findOneActivateByOwner(User $owner): ?ShoppingList
    {
        if (($shoppingListEntity = $this->entityRepository->findOneBy(['ownerId' => $owner->getId(), 'status' => ShoppingListStatus::ACTIVE])) !== null) {
            return $this->convertEntityToModel($shoppingListEntity);
        }

        return null;
    }

    public function delete(ShoppingList $shoppingList): void
    {
        $this->modelCache->removeModelCache($shoppingList);

        // Suppression des lignes
        $this->shopListRowRepository->deleteByShoppingListId($shoppingList->getId());

        // Suppression des ingredients
        $this->shopListIngredientRepository->deleteByShoppingListId($shoppingList->getId());

        // Suppression des repas
        $this->mealRepository->deleteByShoppingListId($shoppingList->getId());

        // Suppression de la liste
        $this->entityRepository->createQueryBuilder('sl')
            ->delete(ShoppingListEntity::class, 'sl')
            ->where('sl.id = :shoppingListId')
            ->setParameter('shoppingListId', $shoppingList->getId())
            ->getQuery()
            ->execute();
    }

    /**
     * @return Tab<ShoppingList>
     */
    public function findByOwnerAndStatus(User $owner, ShoppingListStatus $status): Tab
    {
        return new Tab($this->entityRepository->findBy(['ownerId' => $owner->getId(), 'status' => $status]), ShoppingListEntity::class)
            ->map(fn(ShoppingListEntity $entity) => $this->convertEntityToModel($entity));
    }


    /**
     * @throws UserException
     */
    private function convertEntityToModel(ShoppingListEntity $shoppingListEntity): ShoppingList
    {
        if (($model = $this->modelCache->getModelCache(ShoppingList::class, $shoppingListEntity->getId())) !== null) {
            return $model;
        }

        $model = ShoppingList::load([
            'id' => $shoppingListEntity->getId(),
            'owner' => $this->userRepository->findOneById($shoppingListEntity->getOwnerId()),
            'created_at' => $shoppingListEntity->getCreatedAt(),
            'status' => $shoppingListEntity->getStatus(),
            'meals' => $this->mealRepository->findByShoppingListId($shoppingListEntity->getId()),
            'ingredients' => $this->shopListIngredientRepository->findByShoppingListId($shoppingListEntity->getId()),
            'rows' => $this->shopListRowRepository->findByShoppingListId($shoppingListEntity->getId()),
        ]);

        $this->modelCache->setModelCache($model);
        return $model;
    }

    private function convertEntitiesToModels(Tab $entities): Tab
    {
        return $entities->map(fn(ShoppingListEntity $entity) => $this->convertEntityToModel($entity));
    }

    public function findByIngredient(Ingredient $ingredient): Tab
    {
        $ids = new Tab($this->entityManager->createQueryBuilder()
            ->select('sli.shoppingListId')
            ->from(ShoppingListIngredient::class, 'sli')
            ->where('sli.ingredientSlug = :ingredientId')
            ->setParameter('ingredientId', $ingredient->getId())
            ->getQuery()
            ->getResult()
        )->map(fn(array $item) => $item['shoppingListId']);

        return $this->findByIds($ids);
    }

    private function findByIds(Tab $ids): Tab
    {
        $entities = new Tab(
            $this->entityRepository->createQueryBuilder('sl')
                ->where('sl.id IN (:ids)')
                ->setParameter('ids', $ids->toArray())
                ->getQuery()
                ->getResult(),
            ShoppingListEntity::class
        );

        return $this->convertEntitiesToModels($entities);
    }
}
