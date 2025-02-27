<?php

namespace Repas\Repas\Infrastructure\Repository;


use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Repas\Domain\Model\RecipeType;
use Repas\Repas\Infrastructure\Entity\Recipe as RecipeEntity;
use Repas\Repas\Infrastructure\Entity\RecipeRow as RecipeRowEntity;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Infrastructure\Repository\ModelCache;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;

readonly class RecipePostgreSQLRepository extends PostgreSQLRepository implements RecipeRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
        private ModelCache $modelCache,
        private UserRepository $userRepository,
        private RecipeTypeRepository $recipeTypeRepository,
        private RecipeRowPostgreSQLRepository $recipeRowRepository,
        private IngredientRepository $ingredientRepository,
    ) {
        parent::__construct($managerRegistry, RecipeEntity::class);
    }

    /**
     * @throws RecipeException
     * @throws UserException
     */
    public function findOneById(string $id): Recipe
    {
        if (($model = $this->modelCache->getModelCache(Recipe::class, $id)) !== null) {
            return $model;
        }

        if (($entity = $this->entityRepository->find($id)) !== null) {
            return $this->convertEntityToModel($entity);
        }

        throw RecipeException::notFound($id);
    }

    public function findByAuthor(User $author): Tab
    {
        return $this->findBy(['authorId' => $author->getId()]);
    }

    public function findByAuthorAndType(User $author, RecipeType $type): Tab
    {
        return $this->findBy(
            ['authorId' => $author->getId(), 'typeSlug' => $type->getSlug()],
            ['slug' => 'ASC']
        );
    }


    public function findBy(array $criteria, ?array $orderBy = null): Tab
    {
        $entities = new Tab($this->entityRepository->findBy($criteria, $orderBy), RecipeEntity::class);
        return $this->convertEntitiesToModels($entities);
    }


    public function save(Recipe $recipe): void
    {
        $this->modelCache->setModelCache($recipe);
        $recipeEntity = $this->entityRepository->find($recipe->getId());
        if ($recipeEntity === null) {
            $recipeEntity = RecipeEntity::fromModel($recipe);
            $this->entityManager->persist($recipeEntity);
        } else {
            $recipeEntity->updateFromModel($recipe);
            // Supprime les anciennes lignes de la recette
            $this->recipeRowRepository->deleteByRecipeIdExceptIds(
                $recipeEntity->getId(),
                $recipe->getRows()->map(fn(RecipeRow $item) => $item->getId())
            );
        }
        // Ajoute les nouvelles lignes ou modifie les lignes existantes
        foreach ($recipe->getRows() as $row) {
            $this->recipeRowRepository->save($row);
        }

        $this->entityManager->flush();
        $this->modelCache->setModelCache($recipe);
    }

    /**
     * @throws UserException
     * @throws RecipeException
     */
    private function convertEntityToModel(RecipeEntity $entity): Recipe
    {
        if (($model = $this->modelCache->getModelCache(Recipe::class, $entity->getSlug())) !== null) {
            return $model;
        }

        $this->ingredientRepository->cachedByRecipe($entity->getId());
        $model = Recipe::load([
            'id' => $entity->getId(),
            'name' => $entity->getName(),
            'serving' => $entity->getServing(),
            'author' => $this->userRepository->findOneById($entity->getAuthorId()),
            'type' => $this->recipeTypeRepository->findOneBySlug($entity->getTypeSlug()),
            'rows' => $this->recipeRowRepository->findByRecipeId($entity->getId()),
            'original_id' => $entity->getOriginalId(),
        ]);

        $this->modelCache->setModelCache($model);
        return $model;
    }

    public function findByIngredient(Ingredient $ingredient): Tab
    {
        $ids = new Tab($this->entityManager->createQueryBuilder()
            ->select('rr.recipeId')
            ->from(RecipeRowEntity::class, 'rr')
            ->where('rr.ingredientSlug = :ingredient')
            ->setParameter('ingredient', $ingredient->getId())
            ->getQuery()
            ->getResult()
        )->map(fn(array $item) => $item['recipeId']);

        return $this->findByIds($ids);
    }


    private function findByIds(Tab $ids): Tab
    {
        $entities = new Tab(
            $this->entityRepository->createQueryBuilder('r')
                ->where('r.id IN (:ids)')
                ->setParameter('ids', $ids->toArray())
                ->getQuery()
                ->getResult(),
            RecipeEntity::class
        );

        return $this->convertEntitiesToModels($entities);
    }

    private function convertEntitiesToModels(Tab $entities): Tab
    {
        return $entities->map(fn(RecipeEntity $entity) => $this->convertEntityToModel($entity), Recipe::class);
    }

    public function delete(Recipe $recipe): void
    {
        $this->modelCache->removeModelCache($recipe);
        $this->entityRepository->createQueryBuilder('r')
            ->delete()
            ->where('r.id = :id')
            ->setParameter('id', $recipe->getId())
            ->getQuery()
            ->execute();
    }
}
