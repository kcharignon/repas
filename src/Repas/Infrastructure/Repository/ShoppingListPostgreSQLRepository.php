<?php

namespace Repas\Repas\Infrastructure\Repository;


use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\MealRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Infrastructure\Entity\Meal;
use Repas\Repas\Infrastructure\Entity\ShoppingList as ShoppingListEntity;
use Repas\Shared\Domain\Exception\SharedException;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Infrastructure\Repository\ModelCache;
use Repas\Shared\Infrastructure\Repository\RepositoryTrait;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Repas\User\Infrastructure\Entity\User as UserEntity;

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
     * @return ShoppingList[]
     */
    public function findByOwner(User $owner): array
    {
        $shoppingListEntities = $this->findBy(['owner' => $owner->getId()], ['createdAt' => 'DESC']);
        return array_map(fn(ShoppingListEntity $entity) => $entity->getModel(), $shoppingListEntities);
    }

    /**
     * @throws ShoppingListException
     */
    public function findById(string $id): ShoppingList
    {
        $shoppingListEntity = $this->find($id);

        if (null === $shoppingListEntity) {
            throw ShoppingListException::shoppingListNotFound();
        }

        return $shoppingListEntity?->getModel();
    }

    public function save(ShoppingList $shoppingList): void
    {
        ShoppingListEntity::fromModel($shoppingList);
        $this->getEntityManager()->persist($shoppingList);
        $this->getEntityManager()->flush();
    }

    public function findOneBy(array $criteria, ?array $orderBy = null): ?ShoppingList
    {
        $criteria = $this->convertModelCriteriaToEntityCriteria($criteria);
        /** @var ShoppingListEntity|null $shoppingListEntity */
        $shoppingListEntity = parent::findOneBy($criteria, $orderBy);
        return $shoppingListEntity?->getModel();
    }

    public function convertEntityToModel(ShoppingListEntity $shoppingListEntity): ShoppingList
    {
        $meals = $this->mealRepository->findByShoppingListId($shoppingListEntity->getId());

        return ShoppingList::load([
            'id' => $shoppingListEntity->getId(),
            'owner' => $this->userRepository->findOneById($shoppingListEntity->getOwnerId()),
            'createdAt' => $shoppingListEntity->getCreatedAt(),
            'locked' => $shoppingListEntity->isLocked(),
            'meals' => $meals,
        ]);
    }
}
