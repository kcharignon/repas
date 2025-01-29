<?php

namespace Repas\Repas\Infrastructure\Repository;


use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\MealRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Meal as MealModel;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Infrastructure\Entity\ShoppingList as ShoppingListEntity;
use Repas\Shared\Domain\Exception\SharedException;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Infrastructure\Repository\ModelCache;
use Repas\Shared\Infrastructure\Repository\RepositoryTrait;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;

class ShoppingListPostgreSQLRepository extends ServiceEntityRepository implements ShoppingListRepository
{
    use RepositoryTrait;

    public function __construct(
        ManagerRegistry $registry,
        private readonly ModelCache $modelCache,
        private readonly UserRepository $userRepository,
        private readonly MealPostgreSQLRepository $mealRepository,
    ) {
        parent::__construct($registry, ShoppingListEntity::class);
    }

    /**
     * @return Tab<ShoppingList>
     */
    public function getByOwner(User $owner): Tab
    {
        $shoppingListEntities = Tab::fromArray($this->findBy(['owner' => $owner->getId()], ['createdAt' => 'DESC']));
        return $shoppingListEntities->map(fn(ShoppingListEntity $entity) => $this->convertEntityToModel($entity));
    }

    /**
     * @throws ShoppingListException
     */
    public function getOneById(string $id): ShoppingList
    {
        $shoppingListEntity = $this->find($id);

        if (null === $shoppingListEntity) {
            throw ShoppingListException::shoppingListNotFound();
        }

        return $shoppingListEntity?->getModel();
    }

    public function save(ShoppingList $shoppingList): void
    {
        $this->modelCache->removeModelCache($shoppingList);
        $shoppingListEntity = $this->find($shoppingList->getId());
        if (null === $shoppingListEntity) {
            ShoppingListEntity::fromModel($shoppingList);
            $this->getEntityManager()->persist($shoppingList);
        } else {
            $shoppingListEntity->updateFromModel($shoppingList);
            // On supprime les anciens repas
            $this->mealRepository->deleteByShoppingListIdExceptIds(
                $shoppingListEntity->getId(),
                $shoppingList->getMeals()->map(fn(MealModel $meal) => $meal->getId())
            );
        }
        // On sauvegarde les nouveaux repas et modifie les autres
        foreach ($shoppingList->getMeals() as $meal) {
            $this->mealRepository->save($meal);
        }
        $this->getEntityManager()->flush();
        $this->modelCache->setModelCache($shoppingList);
    }

    public function getOneActiveByOwner(User $owner): ?ShoppingList
    {
        if (count($shoppingListEntity = $this->findBy(['owner' => $owner->getId(), 'locked' => false])) === 1)
        {
            $shoppingListModel = $this->convertEntityToModel(current($shoppingListEntity));
            $this->modelCache->setModelCache($shoppingListModel);
            return $shoppingListModel;
        }

        return null;
    }

    public function convertEntityToModel(ShoppingListEntity $shoppingListEntity): ShoppingList
    {
        return ShoppingList::load([
            'id' => $shoppingListEntity->getId(),
            'owner' => $this->userRepository->findOneById($shoppingListEntity->getOwnerId()),
            'created_at' => $shoppingListEntity->getCreatedAt(),
            'locked' => $shoppingListEntity->isLocked(),
            'meals' => $this->mealRepository->findByShoppingListId($shoppingListEntity->getId()),
        ]);
    }
}
