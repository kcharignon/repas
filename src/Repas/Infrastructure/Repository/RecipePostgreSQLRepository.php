<?php

namespace Repas\Repas\Infrastructure\Repository;


use Repas\Shared\Infrastructure\Repository\PostgreSQLRepository;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Repas\Domain\Model\RecipeType;
use Repas\Repas\Infrastructure\Entity\Recipe as RecipeEntity;
use Repas\Repas\Infrastructure\Entity\RecipeRow as RecipeRowEntity;
use Repas\Shared\Domain\Tool\Tab;

readonly class RecipePostgreSQLRepository extends PostgreSQLRepository implements RecipeRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
        private RecipeTypeRepository $recipeTypeRepository,
        private RecipeRowPostgreSQLRepository $recipeRowRepository,
        private IngredientPostgreSQLRepository $ingredientRepository,
    ) {
        parent::__construct($managerRegistry, RecipeEntity::class);
    }

    /**
     * @throws RecipeException
     */
    public function findOneById(string $id): Recipe
    {
        if (($entity = $this->entityRepository->find($id)) !== null) {
            return $this->convertEntityToModel($entity);
        }

        throw RecipeException::notFound($id);
    }

    public function findByAuthor(string $authorId): Tab
    {
        return $this->findBy(['authorId' => $authorId]);
    }

    public function findBy(array $criteria, ?array $orderBy = null): Tab
    {
        $entities = new Tab($this->entityRepository->findBy($criteria, $orderBy), RecipeEntity::class);
        return $this->convertEntitiesToModels($entities);
    }


    public function save(Recipe $recipe): void
    {
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
    }

    /**
     * @throws RecipeException
     */
    private function convertEntityToModel(RecipeEntity $entity): Recipe
    {
        $this->ingredientRepository->preloadByRecipe($entity->getId());

        return Recipe::load([
            'id' => $entity->getId(),
            'name' => $entity->getName(),
            'serving' => $entity->getServing(),
            'author_id' => $entity->getAuthorId(),
            'type' => $this->recipeTypeRepository->findOneBySlug($entity->getTypeSlug()),
            'rows' => $this->recipeRowRepository->findByRecipeId($entity->getId()),
            'original_id' => $entity->getOriginalId(),
        ]);
    }

    public function isIngredientUsed(string $ingredientSlug): bool
    {
        return $this->entityManager->createQueryBuilder()
            ->select('rr.recipeId')
            ->from(RecipeRowEntity::class, 'rr')
            ->where('rr.ingredientSlug = :ingredient')
            ->setParameter('ingredient', $ingredientSlug)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }

    private function convertEntitiesToModels(Tab $entities): Tab
    {
        return $entities->map(fn(RecipeEntity $entity) => $this->convertEntityToModel($entity), Recipe::class);
    }

    public function delete(Recipe $recipe): void
    {
        $this->recipeRowRepository->deleteByRecipeId($recipe->getId());
        $this->entityRepository->createQueryBuilder('r')
            ->delete()
            ->where('r.id = :id')
            ->setParameter('id', $recipe->getId())
            ->getQuery()
            ->execute();
    }
}
