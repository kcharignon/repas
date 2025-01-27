<?php

namespace Repas\Repas\Infrastructure\Repository;


use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Infrastructure\Entity\ShoppingList as ShoppingListEntity;
use Repas\Shared\Domain\Exception\SharedException;
use Repas\Shared\Infrastructure\Repository\RepositoryTrait;
use Repas\User\Domain\Model\User;
use Repas\User\Infrastructure\Entity\User as UserEntity;

class ShoppingListPostgreSQLRepository extends ServiceEntityRepository implements ShoppingListRepository
{
    use RepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShoppingListEntity::class);
    }

    /**
     * @return ShoppingList[]
     */
    public function findByOwner(User $owner): array
    {
        $userEntity = UserEntity::fromModel($owner);
        $shoppingListEntities = $this->findBy(['owner' => $userEntity], ['createdAt' => 'DESC']);
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
//        dump($criteria);
        /** @var ShoppingListEntity|null $shoppingListEntity */
        $shoppingListEntity = parent::findOneBy($criteria, $orderBy);
//        dd($shoppingListEntity);
        return $shoppingListEntity?->getModel();
    }
}
