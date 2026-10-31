<?php

namespace Repas\Repas\Infrastructure\Repository;


use Repas\Shared\Infrastructure\Repository\PostgreSQLRepository;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Exception\MealException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Meal as MealModel;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListIngredient as ShoppingListIngredientModel;
use Repas\Repas\Domain\Model\ShoppingListRow as ShoppingListRowModel;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Repas\Infrastructure\Entity\Meal;
use Repas\Repas\Infrastructure\Entity\Recipe as RecipeEntity;
use Repas\Repas\Infrastructure\Entity\ShoppingList as ShoppingListEntity;
use Repas\Repas\Infrastructure\Entity\ShoppingListIngredient;
use Repas\Repas\Infrastructure\Entity\ShoppingListRow;
use Repas\Shared\Domain\Tool\Tab;

readonly class ShoppingListPostgreSQLRepository extends PostgreSQLRepository implements ShoppingListRepository
{
    public function __construct(
        ManagerRegistry                                    $managerRegistry,
        private MealPostgreSQLRepository                   $mealRepository,
        private ShoppingListIngredientPostgreSQLRepository $shopListIngredientRepository,
        private ShoppingListRowPostgreSQLRepository        $shopListRowRepository,
    ) {
        parent::__construct($managerRegistry, ShoppingListEntity::class);
    }

    /**
     * @return Tab<ShoppingList>
     */
    public function findByOwner(string $ownerId): Tab
    {
        $shoppingListEntities = Tab::fromArray($this->entityRepository->findBy(['ownerId' => $ownerId], ['createdAt' => 'DESC']));
        return $this->convertEntitiesToModels($shoppingListEntities);
    }

    /**
     * @throws ShoppingListException
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
     */
    public function findOneByMealId(string $mealId): ShoppingList
    {
        $meal = $this->mealRepository->findOneById($mealId);
        return $this->findOneById($meal->getShoppingListId());
    }


    /**
     * @throws ShoppingListException une autre requête a enregistré la liste depuis sa lecture (ADR 0016)
     */
    public function save(ShoppingList $shoppingList): void
    {
        $shoppingListEntity = $this->entityRepository->find($shoppingList->getId());
        if (!$shoppingListEntity instanceof ShoppingListEntity) {
            $shoppingListEntity = ShoppingListEntity::fromModel($shoppingList);
            $this->entityManager->persist($shoppingListEntity);
        } else {
            // Avant toute écriture : si la liste a changé depuis sa lecture, rien n'est enregistré
            if (!$this->bumpVersion($shoppingListEntity->getId(), $shoppingListEntity->getVersion())) {
                throw ShoppingListException::concurrentModification($shoppingList->getId());
            }
            $shoppingListEntity->setVersion($shoppingListEntity->getVersion() + 1);
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
        $this->shopListRowRepository->saveAll($shoppingList->getRows());

        $this->entityManager->flush();
    }

    /**
     */
    public function findOneActivateByOwner(string $ownerId): ?ShoppingList
    {
        if (($shoppingListEntity = $this->entityRepository->findOneBy(['ownerId' => $ownerId, 'status' => ShoppingListStatus::ACTIVE])) !== null) {
            return $this->convertEntityToModel($shoppingListEntity);
        }

        return null;
    }

    public function ownerHasAtLeast(string $ownerId, int $count): bool
    {
        // La `$count`-ième liste à garder existe-t-elle ? Une ligne lue après avoir sauté les `$count - 1` premières
        return $this->entityManager->createQueryBuilder()
            ->select('sl.id')
            ->from(ShoppingListEntity::class, 'sl')
            ->where('sl.ownerId = :owner')
            ->andWhere('sl.status != :completed')
            ->setParameter('owner', $ownerId)
            ->setParameter('completed', ShoppingListStatus::COMPLETED)
            ->setFirstResult(max(0, $count - 1))
            ->setMaxResults(1)
            ->getQuery()
            ->getScalarResult() !== [];
    }

    public function hasActiveShoppingList(string $ownerId): bool
    {
        return $this->entityRepository->findOneBy(['ownerId' => $ownerId, 'status' => ShoppingListStatus::ACTIVE]) !== null;
    }

    public function hasSentShoppingList(string $ownerId): bool
    {
        return $this->entityRepository->findOneBy(['ownerId' => $ownerId, 'status' => ShoppingListStatus::SENT]) !== null;
    }

    public function isSent(string $shoppingListId): bool
    {
        return $this->entityManager->createQueryBuilder()
            ->select('sl.id')
            ->from(ShoppingListEntity::class, 'sl')
            ->where('sl.id = :id')
            ->andWhere('sl.status = :status')
            ->setParameter('id', $shoppingListId)
            ->setParameter('status', ShoppingListStatus::SENT)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }

    public function delete(ShoppingList $shoppingList): void
    {

        // Suppression des lignes
        $this->shopListRowRepository->deleteByShoppingListId($shoppingList->getId());

        // Suppression des ingredients
        $this->shopListIngredientRepository->deleteByShoppingListId($shoppingList->getId());

        // Suppression des repas
        $this->mealRepository->deleteByShoppingListId($shoppingList->getId());

        // Suppression de la liste
        $entity = $this->entityManager->find(ShoppingListEntity::class, $shoppingList->getId());
        $this->entityManager->remove($entity);
        $this->entityManager->flush();
    }

    public function findIdsByOwnerAndStatus(string $ownerId, ShoppingListStatus $status): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('sl.id AS id')
            ->from(ShoppingListEntity::class, 'sl')
            ->where('sl.ownerId = :ownerId')
            ->andWhere('sl.status = :status')
            ->orderBy('sl.createdAt', 'DESC')
            ->setParameter('ownerId', $ownerId)
            ->setParameter('status', $status)
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'id');
    }


    /**
     */
    private function convertEntityToModel(ShoppingListEntity $shoppingListEntity): ShoppingList
    {
        return ShoppingList::load([
            'id' => $shoppingListEntity->getId(),
            'owner_id' => $shoppingListEntity->getOwnerId(),
            'created_at' => $shoppingListEntity->getCreatedAt(),
            'status' => $shoppingListEntity->getStatus(),
            'name' => $shoppingListEntity->getName(),
            'meals' => $this->mealRepository->findByShoppingListId($shoppingListEntity->getId()),
            'ingredients' => $this->shopListIngredientRepository->findByShoppingListId($shoppingListEntity->getId()),
            'rows' => $this->shopListRowRepository->findByShoppingListId($shoppingListEntity->getId()),
            'version' => $shoppingListEntity->getVersion(),
            'sent_at' => $shoppingListEntity->getSentAt(),
            'list_type_id' => $shoppingListEntity->getListTypeId(),
        ]);
    }

    private function convertEntitiesToModels(Tab $entities): Tab
    {
        return $entities->map(fn(ShoppingListEntity $entity) => $this->convertEntityToModel($entity));
    }

    public function isIngredientUsed(string $ingredientSlug): bool
    {
        return $this->entityManager->createQueryBuilder()
            ->select('sli.shoppingListId')
            ->from(ShoppingListIngredient::class, 'sli')
            ->where('sli.ingredientSlug = :ingredient')
            ->setParameter('ingredient', $ingredientSlug)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }

    public function deleteByOwner(string $ownerId): void
    {
        $ownedLists = 'SELECT sl.id FROM ' . ShoppingListEntity::class . ' sl WHERE sl.ownerId = :owner';

        foreach ([ShoppingListRow::class, ShoppingListIngredient::class, Meal::class] as $childClass) {
            $this->entityManager->createQueryBuilder()
                ->delete($childClass, 'child')
                ->where('child.shoppingListId IN (' . $ownedLists . ')')
                ->setParameter('owner', $ownerId)
                ->getQuery()
                ->execute();
        }

        $this->entityManager->createQueryBuilder()
            ->delete(ShoppingListEntity::class, 'sl')
            ->where('sl.ownerId = :owner')
            ->setParameter('owner', $ownerId)
            ->getQuery()
            ->execute();
    }

    public function removeMealsOfRecipes(array $recipeIds): void
    {
        if ($recipeIds === []) {
            return;
        }

        $this->entityManager->createQueryBuilder()
            ->delete(Meal::class, 'm')
            ->where('m.recipeId IN (:recipeIds)')
            ->setParameter('recipeIds', $recipeIds)
            ->getQuery()
            ->execute();
    }

    public function detachFromListType(string $ownerId, string $listTypeId): void
    {
        $table = $this->entityManager->getClassMetadata(ShoppingListEntity::class)->getTableName();

        // Une seule instruction : le côté droit d'un SET lit la ligne d'AVANT, le statut testé est donc bien celui de départ. La version monte
        // de 1 pour la seule liste qui change de statut (ADR 0016 : elle reste lisible par son ETag, `bumpVersion` écrit la colonne de même)
        $this->entityManager->getConnection()->executeStatement(
            sprintf(
                'UPDATE %1$s SET list_type_id = NULL, status = CASE WHEN status = :active THEN :paused ELSE status END, version = CASE WHEN status = :active THEN version + 1 ELSE version END WHERE owner = :owner AND list_type_id = :type',
                $table,
            ),
            [
                'active' => ShoppingListStatus::ACTIVE->value,
                'paused' => ShoppingListStatus::PAUSED->value,
                'owner' => $ownerId,
                'type' => $listTypeId,
            ],
        );
    }

    public function isRecipePlanned(string $recipeId): bool
    {
        return $this->entityManager->createQueryBuilder()
            ->select('m.shoppingListId')
            ->from(Meal::class, 'm')
            ->where('m.recipeId = :recipe')
            ->setParameter('recipe', $recipeId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }
}
