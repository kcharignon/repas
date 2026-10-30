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


    /**
     * @throws RecipeException une autre requête a enregistré la recette depuis sa lecture (ADR 0016)
     */
    public function save(Recipe $recipe): void
    {
        $recipeEntity = $this->entityRepository->find($recipe->getId());
        if ($recipeEntity === null) {
            $recipeEntity = RecipeEntity::fromModel($recipe);
            $this->entityManager->persist($recipeEntity);
        } else {
            // Avant toute écriture : si la recette a changé depuis sa lecture, rien n'est enregistré
            if (!$this->bumpVersion($recipeEntity->getId(), $recipeEntity->getVersion())) {
                throw RecipeException::concurrentModification($recipe->getId());
            }
            $recipeEntity->setVersion($recipeEntity->getVersion() + 1);
            $recipeEntity->updateFromModel($recipe);
            // Supprime les anciennes lignes de la recette
            $this->recipeRowRepository->deleteByRecipeIdExceptIds(
                $recipeEntity->getId(),
                $recipe->getRows()->map(fn(RecipeRow $item) => $item->getId())
            );
        }
        // Ajoute les nouvelles lignes ou modifie les lignes existantes
        $this->recipeRowRepository->saveAll($recipe->getRows());

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
            'version' => $entity->getVersion(),
            'secret' => $entity->isSecret(),
            'favorite' => $entity->isFavorite(),
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

    /**
     * @throws RecipeException une autre requête a enregistré la recette depuis sa lecture (ADR 0016)
     */
    public function delete(Recipe $recipe): void
    {
        // La version est celle de l'entité (partagée par toutes les copies du modèle dans la requête), pas celle du modèle
        $entity = $this->entityRepository->find($recipe->getId());
        if ($entity instanceof RecipeEntity) {
            // Avant les lignes : si la recette a changé depuis sa lecture, rien n'est supprimé
            $deleted = $this->entityRepository->createQueryBuilder('r')
                ->delete()
                ->where('r.id = :id')
                ->andWhere('r.version = :version')
                ->setParameter('id', $recipe->getId())
                ->setParameter('version', $entity->getVersion())
                ->getQuery()
                ->execute();
            if ($deleted === 0) {
                throw RecipeException::concurrentModification($recipe->getId());
            }
        }

        $this->recipeRowRepository->deleteByRecipeId($recipe->getId());
    }

    public function authorHasAtLeast(string $authorId, int $count): bool
    {
        // La `$count`-ième recette existe-t-elle ? Une ligne lue après avoir sauté les `$count - 1` premières
        return $this->entityManager->createQueryBuilder()
            ->select('r.id')
            ->from(RecipeEntity::class, 'r')
            ->where('r.authorId = :author')
            ->setParameter('author', $authorId)
            ->setFirstResult(max(0, $count - 1))
            ->setMaxResults(1)
            ->getQuery()
            ->getScalarResult() !== [];
    }

    public function findIdsByAuthor(string $authorId): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('r.id AS id')
            ->from(RecipeEntity::class, 'r')
            ->where('r.authorId = :author')
            ->setParameter('author', $authorId)
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'id');
    }

    public function makeCopiesOriginal(array $recipeIds): void
    {
        if ($recipeIds === []) {
            return;
        }

        // La colonne `version` s'écrit en SQL, comme `bumpVersion()` : une copie modifiée entre-temps est vue comme périmée
        $this->entityManager->createQueryBuilder()
            ->update(RecipeEntity::class, 'r')
            ->set('r.originalId', 'NULL')
            ->set('r.version', 'r.version + 1')
            ->where('r.originalId IN (:originalIds)')
            ->setParameter('originalIds', $recipeIds)
            ->getQuery()
            ->execute();
    }

    public function deleteByAuthor(string $authorId): void
    {
        $this->entityManager->createQueryBuilder()
            ->delete(RecipeRowEntity::class, 'rw')
            ->where('rw.recipeId IN (SELECT r.id FROM ' . RecipeEntity::class . ' r WHERE r.authorId = :author)')
            ->setParameter('author', $authorId)
            ->getQuery()
            ->execute();

        $this->entityManager->createQueryBuilder()
            ->delete(RecipeEntity::class, 'r')
            ->where('r.authorId = :author')
            ->setParameter('author', $authorId)
            ->getQuery()
            ->execute();
    }

    public function moveToType(string $fromTypeSlug, string $toTypeSlug): void
    {
        // La colonne `version` s'écrit en SQL, comme `bumpVersion()` : une recette lue avant le déplacement est vue comme périmée
        $this->entityManager->createQueryBuilder()
            ->update(RecipeEntity::class, 'r')
            ->set('r.typeSlug', ':to')
            ->set('r.version', 'r.version + 1')
            ->where('r.typeSlug = :from')
            ->setParameter('from', $fromTypeSlug)
            ->setParameter('to', $toTypeSlug)
            ->getQuery()
            ->execute();
    }
}
