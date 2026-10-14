<?php

namespace Repas\Repas\Infrastructure\Repository;


use Repas\Shared\Infrastructure\Repository\PostgreSQLRepository;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\DepartmentRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\Department;
use Repas\Repas\Domain\Model\Ingredient as IngredientModel;
use Repas\Repas\Domain\Model\Unit;
use Repas\Repas\Infrastructure\Entity\Ingredient as IngredientEntity;
use Repas\Repas\Infrastructure\Entity\RecipeRow;
use Repas\Shared\Domain\Tool\Tab;

readonly class IngredientPostgreSQLRepository extends PostgreSQLRepository implements IngredientRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
        private UnitRepository $unitRepository,
        private DepartmentRepository $departmentRepository,
    ) {
        parent::__construct($managerRegistry, IngredientEntity::class);
    }

    /**
     * @throws DepartmentException
     * @throws UnitException
     * @throws IngredientException
     */
    public function findOneBySlug(string $slug): IngredientModel
    {
        // Doctrine ne relit pas en base une entité déjà dans son identity map
        if (($entity = $this->entityRepository->find($slug)) !== null) {
            return $this->convertEntityToModel($entity);
        }

        throw IngredientException::notFound($slug);
    }

    public function exists(string $slug): bool
    {
        return $this->entityRepository->find($slug) !== null;
    }

    public function findAll(): Tab
    {
        $entities = $this->entityRepository->findBy([], ['sluggedName' => 'ASC']);

        return $this->convertEntitiesToModels(new Tab($entities, IngredientEntity::class));
    }

    public function findByDepartmentAndOwner(Department $department, string $ownerId): Tab
    {
        $entities = $this->entityRepository->createQueryBuilder('i')
            ->where('i.departmentSlug = :department')
            ->andWhere('i.creatorId = :owner or i.creatorId is null')
            ->setParameter('department', $department->getId())
            ->setParameter('owner', $ownerId)
            ->orderBy('i.sluggedName', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->convertEntitiesToModels(new Tab($entities, IngredientEntity::class));
    }

    public function findByOwner(string $ownerId): Tab
    {
        $entities = $this->entityRepository->createQueryBuilder('i')
            ->where('i.creatorId = :owner or i.creatorId is null')
            ->setParameter('owner', $ownerId)
            ->orderBy('i.sluggedName', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->convertEntitiesToModels(new Tab($entities, IngredientEntity::class));
    }

    public function save(IngredientModel $ingredient): void
    {
        $ingredientEntity = $this->entityRepository->find($ingredient->getSlug());
        if ($ingredientEntity) {
            $this->updateEntity($ingredientEntity, $ingredient);
        } else {
            $ingredientEntity = IngredientEntity::fromModel($ingredient);
        }

        $this->entityManager->persist($ingredientEntity);
        $this->entityManager->flush();
    }

    private function updateEntity(IngredientEntity $ingredientEntity, IngredientModel $ingredient): void
    {
        $ingredientEntity
            ->setName($ingredient->getName())
            ->setImage($ingredient->getImage())
            ->setSluggedName($ingredient->getSluggedName())
            ->setDepartmentSlug($ingredient->getDepartment()->getSlug())
            ->setDefaultCookingUnitSlug($ingredient->getDefaultCookingUnit()->getSlug())
            ->setDefaultPurchaseUnitSlug($ingredient->getDefaultPurchaseUnit()->getSlug())
            ->setCompatibleUnitSlugs($ingredient->getCompatibleUnits()->map(fn(Unit $unit) => $unit->getSlug())->toArray())
        ;
    }

    /**
     * @throws UnitException
     * @throws DepartmentException
     */
    private function convertEntityToModel(IngredientEntity $ingredientEntity): IngredientModel
    {
        return IngredientModel::load([
            "slug" => $ingredientEntity->getSlug(),
            "name" => $ingredientEntity->getName(),
            "image" => $ingredientEntity->getImage(),
            "slugged_name" => $ingredientEntity->getSluggedName(),
            "department" => $this->departmentRepository->findOneBySlug($ingredientEntity->getDepartmentSlug()),
            "default_cooking_unit" => $this->unitRepository->findOneBySlug($ingredientEntity->getDefaultCookingUnitSlug()),
            "default_purchase_unit" => $this->unitRepository->findOneBySlug($ingredientEntity->getDefaultPurchaseUnitSlug()),
            "creator_id" => $ingredientEntity->getCreatorId(),
            "compatible_units" => $this->unitRepository->findBySlugs(new Tab($ingredientEntity->getCompatibleUnitSlugs(), 'string')),
        ]);
    }

    private function convertEntitiesToModels(Tab $entities): Tab
    {
        return $entities->map(function (IngredientEntity $ingredient) {
            return $this->convertEntityToModel($ingredient);
        });
    }

    /**
     * Précharge par lot les ingrédients d'une recette, avec leurs rayons et leurs unités : les lectures unitaires qui
     * suivent (une par ligne de la recette) sont servies par l'identity map de Doctrine, sans requête.
     */
    public function preloadByRecipe(string $recipeId): void
    {
        // Récupérer les ingrédients liés à la recette
        /** @var Tab<IngredientEntity> $ingredientEntities */
        $ingredientEntities = new Tab(
            $this->entityManager->createQueryBuilder()
                ->select('i')
                ->from(IngredientEntity::class, 'i')
                ->innerJoin(RecipeRow::class, 'rr', 'WITH', 'rr.ingredientSlug = i.slug')
                ->where('rr.recipeId = :recipeId')
                ->setParameter('recipeId', $recipeId)
                ->getQuery()
                ->getResult(),
            type: IngredientEntity::class
        );

        if (!$ingredientEntities->count()) {
            return; // Aucun ingrédient à mettre en cache
        }

        // Extraire tous les slugs de départements et unités à récupérer
        $departmentSlugs = Tab::newEmptyTyped('string');
        $unitSlugs = Tab::newEmptyTyped('string');;

        foreach ($ingredientEntities as $ingredient) {
            $departmentSlugs[] = $ingredient->getDepartmentSlug();
            $unitSlugs->merge(new Tab($ingredient->getCompatibleUnitSlugs()));
        }

        // Charger tous les départements et unités nécessaires en une requête chacun
        $this->departmentRepository->findBySlugs($departmentSlugs->unique());
        $this->unitRepository->findBySlugs($unitSlugs->unique());
    }

    public function delete(IngredientModel $ingredient): void
    {

        // Suppression de la liste
        $entity = $this->entityManager->find(IngredientEntity::class, $ingredient->getId());
        $this->entityManager->remove($entity);
        $this->entityManager->flush();
    }
}
