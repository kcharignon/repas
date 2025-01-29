<?php

namespace Repas\Repas\Infrastructure\Repository;


use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\RecipeRow as RecipeRowModel;
use Repas\Repas\Infrastructure\Entity\RecipeRow as RecipeRowEntity;
use Repas\Shared\Domain\Tool\Tab;

readonly class RecipeRowPostgreSQLRepository extends PostgreSQLRepository
{

    public function __construct(
        ManagerRegistry $managerRegistry,
        private IngredientRepository $ingredientRepository,
        private UnitRepository $unitRepository,
    ) {
        parent::__construct($managerRegistry, RecipeRowEntity::class);
    }

    /**
     * @return Tab<RecipeRowModel>
     */
    public function findByRecipeId(string $recipeId): Tab
    {
        $recipeRows = new Tab($this->entityRepository->findBy(['recipeId' => $recipeId]), RecipeRowEntity::class);

        return $recipeRows->map(fn(RecipeRowEntity $recipeRow) => $this->convertEntityToModel($recipeRow));
    }

    public function save(RecipeRowModel $recipeRow): void
    {

    }

    /**
     * @param Tab<string> $ids
     */
    public function deleteByRecipeIdExceptIds(string $recipeId, Tab $ids): void
    {
        $this->entityRepository->createQueryBuilder('rw')
            ->delete()
            ->where('rw.recipeId = :recipeId')
            ->setParameter('recipeId', $recipeId)
            ->andWhere('rw.id not in (:ids)')
            ->setParameter('ids', $ids->toArray())
            ->getQuery()
            ->execute();
    }

    /**
     * @throws RecipeException
     */
    private function convertEntityToModel(RecipeRowEntity $entity): RecipeRowModel
    {
        try {
            return RecipeRowModel::load([
                'id' => $entity->getId(),
                'recipe_id' => $entity->getRecipeId(),
                'ingredient' => $this->ingredientRepository->getOneBySlug($entity->getIngredientSlug()),
                'quantity' => $entity->getQuantity(),
                'unit' => $this->unitRepository->getOneBySlug($entity->getUnitSlug()),
            ]);
        } catch (IngredientException|UnitException) {
            throw RecipeException::rowSubModelNotFound();
        }
    }
}
