<?php

namespace Repas\Repas\Infrastructure\Repository;

use Repas\Shared\Infrastructure\Repository\PostgreSQLRepository;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\Repas\Domain\Model\RecipeType as RecipeTypeModel;
use Repas\Repas\Infrastructure\Entity\RecipeType as RecipeTypeEntity;
use Repas\Shared\Domain\Tool\Tab;

readonly class RecipeTypePostgreSQLRepository extends PostgreSQLRepository implements RecipeTypeRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
    ) {
        parent::__construct($managerRegistry, RecipeTypeEntity::class);
    }

    /**
     * @return Tab<RecipeTypeModel>
     */
    public function findAll(): Tab
    {
        return Tab::fromArray($this->entityRepository->findBy([], ['sequence' => 'ASC']))
            ->map(fn(RecipeTypeEntity $entity) => $this->convertEntityToModel($entity));
    }

    /**
     * @throws RecipeException
     */
    public function findOneBySlug(string $slug): RecipeTypeModel
    {
        if (($entity = $this->entityRepository->find($slug)) !== null) {
            return $this->convertEntityToModel($entity);
        }

        throw RecipeException::typeNotFound($slug);
    }

    public function exists(string $slug): bool
    {
        return $this->entityRepository->find($slug) !== null;
    }

    public function save(RecipeTypeModel $recipeType): void
    {
        $entity = $this->entityRepository->find($recipeType->getId());

        if ($entity === null) {
            $entity = RecipeTypeEntity::fromModel($recipeType);
            $this->entityManager->persist($entity);
        } else {
            $entity->updateFromModel($recipeType);
        }
        $this->entityManager->flush();
    }

    private function convertEntityToModel(RecipeTypeEntity $entity): RecipeTypeModel
    {
        return RecipeTypeModel::load([
            'slug' => $entity->getSlug(),
            'name' => $entity->getName(),
            'image' => $entity->getImage(),
            'order' => $entity->getSequence(),
        ]);
    }
}
