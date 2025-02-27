<?php

namespace Repas\Repas\Infrastructure\Loader;


use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Repas\Domain\Model\RecipeType;
use Repas\Repas\Infrastructure\Entity\Recipe as RecipeEntity;
use Repas\Repas\Infrastructure\Repository\PostgreSQLRepository;
use Repas\Shared\Domain\Tool\Tab;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;

readonly class RecipeLoader extends PostgreSQLRepository
{

    public function __construct(
        ManagerRegistry $managerRegistry,
        private RecipeTypeRepository $recipeTypeRepository,
        private UserRepository $userRepository,
    ) {
        parent::__construct($managerRegistry, RecipeEntity::class);
    }

    /**
     * @return Tab<Recipe>
     */
    public function findByAuthorAndType(User $author, RecipeType $type): Tab
    {
        return $this->findBy(
            ['authorId' => $author->getId(), 'typeSlug' => $type->getSlug()],
            ['slug' => 'ASC']
        );
    }

    public function findBy(array $criteria, ?array $orderBy = null): Tab
    {
        $recipes = Tab::fromArray($this->entityRepository->findBy($criteria, $orderBy));
        return $this->convertEntitiesToModels($recipes);
    }

    /**
     * @return Tab<Recipe>
     */
    public function findByNotAuthorAndNotCopy(User $author): Tab
    {
        // On récupère les IDs des recettes déjà copiées par l'auteur
        $ids = $this->entityRepository->createQueryBuilder('r')
            ->select('r.originalId')
            ->where('r.authorId = :authorId AND r.originalId IS NOT NULL')
            ->setParameter('authorId', $author->getId())
            ->getQuery()
            ->getSingleColumnResult();

        // On récupère les recettes originales des autres utilisateurs non encore copiées
        $qb = $this->entityRepository->createQueryBuilder('r')
            ->where('r.authorId != :authorId AND r.originalId IS NULL')
            ->setParameter('authorId', $author->getId());

        if (!empty($ids)) {
            $qb->andWhere("r.id NOT IN (:ids)")
                ->setParameter('ids', $ids);
        }

        $entities = $qb->orderBy('r.slug', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->convertEntitiesToModels(new Tab($entities, RecipeEntity::class));
    }

    /**
     * @throws UserException
     * @throws RecipeException
     */
    private function convertEntityToModel(RecipeEntity $entity): Recipe
    {
        return Recipe::load([
            'id' => $entity->getId(),
            'name' => $entity->getName(),
            'serving' => $entity->getServing(),
            'author' => $this->userRepository->findOneById($entity->getAuthorId()),
            'type' => $this->recipeTypeRepository->findOneBySlug($entity->getTypeSlug()),
            'rows' => Tab::newEmptyTyped(RecipeRow::class), // On ne charge pas inutilement le reste
            'original_id' => $entity->getOriginalId(),
        ]);
    }

    /**
     * @param Tab<RecipeEntity> $entities
     * @return Tab<Recipe>
     */
    private function convertEntitiesToModels(Tab $entities): Tab
    {
        return $entities->map(fn (RecipeEntity $entity) => $this->convertEntityToModel($entity));
    }
}
