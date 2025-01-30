<?php

namespace Repas\Repas\Infrastructure\Repository;


use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Repas\Infrastructure\Entity\Recipe as RecipeEntity;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Infrastructure\Repository\ModelCache;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;

readonly class RecipePostgreSQLRepository  extends PostgreSQLRepository implements RecipeRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
        private ModelCache $modelCache,
        private UserRepository $userRepository,
        private RecipeTypeRepository $recipeTypeRepository,
        private RecipeRowPostgreSQLRepository $recipeRowRepository,
    ) {
        parent::__construct($managerRegistry, RecipeEntity::class);
    }

    /**
     * @throws RecipeException
     */
    public function findOneById(string $id): Recipe
    {
        if (($model = $this->modelCache->getModelCache(Recipe::class, $id)) !== null) {
            return $model;
        }

        if (($entity = $this->entityRepository->find($id)) !== null) {
            $model = $this->convertEntityToModel($entity);
            $this->modelCache->setModelCache($model);
            return $model;
        }

        throw RecipeException::notFound($id);
    }

    public function findByAuthor(User $author): Tab
    {
        $recipes = Tab::fromArray($this->entityRepository->findBy(['authorId' => $author->getId()]));
        return $recipes->map(function (RecipeEntity $entity) {
            if (($model = $this->modelCache->getModelCache(Recipe::class, $entity->getId())) !== null) {
                return $model;
            }

            $model = $this->convertEntityToModel($entity);
            $this->modelCache->setModelCache($model);
            return $model;
        });
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

    private function convertEntityToModel(RecipeEntity $entity): Recipe
    {
        return Recipe::load([
            'id' => $entity->getId(),
            'name' => $entity->getName(),
            'serving' => $entity->getServing(),
            'author' => $this->userRepository->findOneById($entity->getAuthorId()),
            'type' => $this->recipeTypeRepository->getOneBySlug($entity->getTypeSlug()),
            'rows' => $this->recipeRowRepository->findByRecipeId($entity->getId()),
        ]);
    }
}
