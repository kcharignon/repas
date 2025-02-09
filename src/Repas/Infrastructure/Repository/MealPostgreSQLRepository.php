<?php

namespace Repas\Repas\Infrastructure\Repository;


use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Exception\MealException;
use Repas\Repas\Domain\Interface\MealRepository;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Model\Meal;
use Repas\Repas\Infrastructure\Entity\Meal as MealEntity;
use Repas\Shared\Domain\Tool\Tab;

readonly class MealPostgreSQLRepository extends PostgreSQLRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
        private RecipeRepository $recipeRepository,
    ) {
        parent::__construct($managerRegistry, MealEntity::class);
    }

    public function save(Meal $meal): void
    {
        $mealEntity = $this->entityRepository->find($meal->getId());
        if (null === $mealEntity) {
            $mealEntity = MealEntity::fromModel($meal);
            $this->entityManager->persist($mealEntity);
        } else {
            $mealEntity->updateFromModel($meal);
        }

        $this->entityManager->flush();
    }


    /**
     * @throws MealException
     */
    public function findOneById(string $id): Meal
    {
        if (($entity = $this->entityRepository->find($id)) !== null) {
            return $this->convertEntityToModel($entity);
        }

        throw MealException::notFound();
    }

    /**
     * @return Tab<Meal>
     */
    public function findByShoppingListId(string $shoppingListId): Tab
    {
        return new Tab($this->entityRepository->findBy(['shoppingListId' => $shoppingListId]))
            ->map(fn(MealEntity $meal) => $this->convertEntityToModel($meal));
    }

    private function convertEntityToModel(MealEntity $meal): Meal
    {
        return Meal::load([
            'id' => $meal->getId(),
            'shopping_list_id' => $meal->getShoppingListId(),
            'recipe' => $this->recipeRepository->findOneById($meal->getRecipeId()),
            'serving' => $meal->getServing(),
        ]);
    }

    /**
     * @param Tab<string> $mealIds
     */
    public function deleteByShoppingListIdExceptIds(string $shoppingListId, Tab $mealIds): void
    {
        $qb = $this->entityRepository->createQueryBuilder('m')
            ->delete()
            ->where('m.shoppingListId = :shoppingListId')
            ->setParameter('shoppingListId', $shoppingListId);
        if ($mealIds->count() > 0) {
            $qb->andWhere('m.id not in (:mealIds)')
                ->setParameter('mealIds', $mealIds->toArray());
        }
        $qb->getQuery()
            ->execute();
    }

    public function deleteByShoppingListId(string $shoppingListId): void
    {
        $this->deleteByShoppingListIdExceptIds($shoppingListId, new Tab([]));
    }
}
