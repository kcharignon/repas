<?php

namespace Repas\Repas\Infrastructure\Repository;


use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\MealRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Meal as MealModel;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListIngredient;
use Repas\Repas\Infrastructure\Entity\ShoppingList as ShoppingListEntity;
use Repas\Shared\Domain\Exception\SharedException;
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
    ) {
        parent::__construct($managerRegistry, ShoppingListEntity::class);
    }

    /**
     * @return Tab<ShoppingList>
     */
    public function findByOwner(User $owner): Tab
    {
        $shoppingListEntities = Tab::fromArray($this->entityRepository->findBy(['ownerId' => $owner->getId()], ['createdAt' => 'DESC']));
        return $shoppingListEntities->map(fn(ShoppingListEntity $entity) => $this->convertEntityToModel($entity));
    }

    /**
     * @throws ShoppingListException
     * @throws UserException
     */
    public function findOneById(string $id): ShoppingList
    {
        $shoppingListEntity = $this->entityRepository->find($id);

        if (null === $shoppingListEntity) {
            throw ShoppingListException::shoppingListNotFound();
        }

        return $this->convertEntityToModel($shoppingListEntity);
    }

    public function save(ShoppingList $shoppingList): void
    {
        $this->modelCache->removeModelCache($shoppingList);
        $shoppingListEntity = $this->entityRepository->find($shoppingList->getId());
        if (null === $shoppingListEntity) {
            $shoppingListEntity = ShoppingListEntity::fromModel($shoppingList);
            $this->entityManager->persist($shoppingListEntity);
        } else {
            $shoppingListEntity->updateFromModel($shoppingList);
            // Suppression des anciens repas
            $this->mealRepository->deleteByShoppingListIdExceptIds(
                $shoppingListEntity->getId(),
                $shoppingList->getMeals()->map(fn(MealModel $meal) => $meal->getId())
            );

            // Suppression des anciens ingredients
            $this->shopListIngredientRepository->deleteByShoppingListIdExceptIds(
                $shoppingListEntity->getId(),
                $shoppingList->getIngredients()->map(fn(ShoppingListIngredient $ingredient) => $ingredient->getId())
            );
        }
        // Ajout des nouveaux repas et mise à jour des autres
        foreach ($shoppingList->getMeals() as $meal) {
            $this->mealRepository->save($meal);
        }
        // Ajout des nouveaux ingredients et mise à jour des autres
        foreach ($shoppingList->getIngredients() as $shopListIngredient) {
            $this->shopListIngredientRepository->save($shopListIngredient);
        }

        $this->entityManager->flush();
        $this->modelCache->setModelCache($shoppingList);
    }

    /**
     * @throws UserException
     */
    public function findOneActiveByOwner(User $owner): ?ShoppingList
    {
        if (($shoppingListEntity = $this->entityRepository->findOneBy(['ownerId' => $owner->getId(), 'locked' => false])) !== null)
        {
            $shoppingListModel = $this->convertEntityToModel($shoppingListEntity);
            $this->modelCache->setModelCache($shoppingListModel);
            return $shoppingListModel;
        }

        return null;
    }

    /**
     * @throws UserException
     */
    public function convertEntityToModel(ShoppingListEntity $shoppingListEntity): ShoppingList
    {
        return ShoppingList::load([
            'id' => $shoppingListEntity->getId(),
            'owner' => $this->userRepository->findOneById($shoppingListEntity->getOwnerId()),
            'created_at' => $shoppingListEntity->getCreatedAt(),
            'locked' => $shoppingListEntity->isLocked(),
            'meals' => $this->mealRepository->findByShoppingListId($shoppingListEntity->getId()),
            'ingredients' => $this->shopListIngredientRepository->findByShoppingListId($shoppingListEntity->getId()),
            'rows' => Tab::fromArray([]),
        ]);
    }
}
